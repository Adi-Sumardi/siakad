<?php

namespace App\Services\Handoff;

use App\Models\AccountInvitation;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use Illuminate\Support\Facades\DB;

/**
 * Issues the single-use link a guardian uses to activate their account (or,
 * via sendReset(), to prove a NEW contact after losing the old one), and
 * delivers it over whichever channel can actually receive it.
 */
class AccountInvitationSender
{
    public const NO_EMAIL = 'Akun wali ini belum punya email. Undangan hanya dikirim lewat email - tambahkan email wali, lalu kirim ulang undangan.';

    public function __construct(
        private MailGateway $mail,
    ) {}

    /**
     * Creates an activation invitation for $user and sends it.
     *
     * Any earlier unused invitation for the same purpose is consumed first, so
     * a resend leaves exactly one working link - otherwise "kirim ulang" would
     * quietly widen the window by leaving both tokens alive.
     *
     * @param  array<string, string>  $context  values the email template needs
     */
    public function send(User $user, array $context, ?User $actor = null): NotificationResult
    {
        // Email only (2026-10-14): no invitation is issued for an account
        // without one - the admin adds an email and sends again.
        if (blank($user->email)) {
            return NotificationResult::fail(self::NO_EMAIL);
        }

        [$invitation, $plainToken] = $this->issue($user, 'activation', $user->email, $actor);

        $data = array_merge($context, [
            'activation_url' => $this->url($plainToken),
            'login_identifier' => $invitation->sent_to,
            'expires_at' => $invitation->expires_at->translatedFormat('d F Y'),
            'guardian_name' => $user->name,
        ]);

        return $this->deliver($invitation, 'school_account_invite', $data);
    }

    /**
     * The lost-access lane: a NEW contact an admin collected in person (the
     * account's own email/phone are dead - that is the whole problem), a
     * reset invitation sent there, and on activation the contact is written
     * onto the account. Self-sufficient message data on purpose: no PMB
     * context exists here, and resend() must be able to rebuild it.
     */
    public function sendReset(User $user, string $contact, ?User $actor = null): NotificationResult
    {
        [$invitation, $plainToken] = $this->issue($user, 'reset', $contact, $actor);

        $data = [
            'activation_url' => $this->url($plainToken),
            'login_identifier' => $invitation->sent_to,
            'expires_at' => $invitation->expires_at->translatedFormat('d F Y'),
            'guardian_name' => $user->name,
        ];

        return $this->deliver($invitation, 'school_account_reset', $data);
    }

    /**
     * The retry sweep's second chance for an invitation whose delivery failed.
     *
     * The stored payload deliberately carries no activation_url (see log()),
     * and the plaintext token lives nowhere but the message that never
     * arrived - so the only way to deliver a working link is to rotate the
     * token on this same invitation row. Safe precisely because the row is
     * failed: the previous link never reached anyone, and overwriting it
     * keeps the "exactly one working link" invariant instead of widening it.
     * expires_at is never extended. Never writes a NotificationLog row; the
     * sweep updates the failed row in place.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        $invitation = $log->notifiable;

        if (! $invitation instanceof AccountInvitation || ! $invitation->isUsable()) {
            // Activated through another path, or past its 7-day TTL - there
            // is no live link left to deliver.
            return NotificationResult::fail('Undangan sudah dipakai atau kedaluwarsa.');
        }

        $plainToken = AccountInvitation::generateToken();

        $invitation->forceFill([
            'token_hash' => AccountInvitation::hashToken($plainToken),
            'sent_count' => $invitation->sent_count + 1,
            'last_sent_at' => now(),
        ])->save();

        $template = $invitation->purpose === 'reset' ? 'school_account_reset' : 'school_account_invite';

        $data = array_merge($log->payload ?? [], [
            'activation_url' => $this->url($plainToken),
            'login_identifier' => $invitation->sent_to,
            'expires_at' => $invitation->expires_at->translatedFormat('d F Y'),
            'guardian_name' => $invitation->user?->name ?? (string) ($log->payload['guardian_name'] ?? ''),
        ]);

        if ($log->channel === 'email') {
            return $this->mail->send($log->recipient, $template, $data);
        }

        // Invitations go by email only (2026-10-14); an old WhatsApp row is
        // not delivered again over a channel the school no longer uses.
        return NotificationResult::fail('Undangan lewat WhatsApp tidak dipakai lagi - kirim undangan baru lewat email.');
    }

    /**
     * One row of truth per (user, purpose): rotates predecessors of that
     * purpose only - a reset link must never consume a live activation link
     * or the other way around.
     *
     * @return array{0: AccountInvitation, 1: string} [$invitation, $plainToken]
     */
    private function issue(User $user, string $purpose, string $contact, ?User $actor): array
    {
        $plainToken = AccountInvitation::generateToken();

        $invitation = DB::transaction(function () use ($user, $purpose, $contact, $actor, $plainToken) {
            $previous = AccountInvitation::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->get();

            $sentCount = 1;

            foreach ($previous as $old) {
                $sentCount = max($sentCount, $old->sent_count + 1);
                $old->markUsed();
            }

            return AccountInvitation::create([
                'user_id' => $user->id,
                'token_hash' => AccountInvitation::hashToken($plainToken),
                'channel' => filter_var($contact, FILTER_VALIDATE_EMAIL) ? 'email' : 'whatsapp',
                'sent_to' => $contact,
                'purpose' => $purpose,
                'expires_at' => now()->addDays(AccountInvitation::TTL_DAYS),
                'sent_count' => $sentCount,
                'last_sent_at' => now(),
                'created_by' => $actor?->id,
            ]);
        });

        return [$invitation, $plainToken];
    }

    private function url(string $plainToken): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/aktivasi?token='.$plainToken;
    }

    /** Sends, then records the attempt whatever the gateway said. */
    private function deliver(AccountInvitation $invitation, string $template, array $data): NotificationResult
    {
        if ($invitation->channel !== 'email') {
            return NotificationResult::fail(self::NO_EMAIL);
        }

        $result = $this->mail->send($invitation->sent_to, $template, $data);

        $this->log($invitation, 'email', $template, $data, $result);

        return $result;
    }

    /**
     * One row per attempt. This is what answers "the parent says no email
     * arrived" without asking the gateway's support desk.
     */
    private function log(AccountInvitation $invitation, string $channel, string $template, array $data, NotificationResult $result): void
    {
        NotificationLog::create([
            'channel' => $channel,
            'template' => $template,
            'recipient' => $invitation->sent_to,
            // The activation URL carries a working credential, so it never
            // reaches the log table.
            'payload' => collect($data)->except('activation_url')->all(),
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => AccountInvitation::class,
            'notifiable_id' => $invitation->id,
        ]);
    }
}
