<?php

namespace App\Services\Handoff;

use App\Jobs\SendQontakTemplateMessage;
use App\Models\AccountInvitation;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\PhoneNumberFormatter;
use Illuminate\Support\Facades\DB;

/**
 * Issues the single-use link a guardian uses to activate their account (or,
 * via sendReset(), to prove a NEW contact after losing the old one), and
 * delivers it over whichever channel can actually receive it.
 */
class AccountInvitationSender
{
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
        // WhatsApp only when there is no email: an address that exists is
        // the channel that carries a clickable link reliably.
        [$invitation, $plainToken] = $this->issue($user, 'activation', $user->email ?: (string) $user->phone, $actor);

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

        // WhatsApp goes through Qontak only (2026-10-14); the sweep updates
        // this same row, so the job is not handed its ulid.
        $templateId = config('services.qontak.account_invite_template_id');

        if (blank($templateId)) {
            return NotificationResult::fail('Template undangan WhatsApp di Qontak belum aktif.');
        }

        $this->dispatchQontak($invitation, $data, $templateId, null);

        return NotificationResult::ok(['mode' => 'queued']);
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
            return $this->deliverWhatsApp($invitation, $template, $data);
        }

        $result = $this->mail->send($invitation->sent_to, $template, $data);

        $this->log($invitation, 'email', $template, $data, $result);

        return $result;
    }

    /**
     * WhatsApp is only for a guardian with no email, and only through an
     * approved Qontak template (2026-10-14): the old Sendago path failed for
     * every such invitation and left a red row in Monitoring each time.
     * Until the template id is configured nothing is sent or logged - the
     * admin is told why instead, and re-sends once it is.
     */
    private function deliverWhatsApp(AccountInvitation $invitation, string $template, array $data): NotificationResult
    {
        $templateId = config('services.qontak.account_invite_template_id');

        if (blank($templateId)) {
            return NotificationResult::fail('Wali ini belum punya email, dan template undangan WhatsApp di Qontak belum aktif. Tambahkan email wali, atau kirim ulang undangan setelah template disetujui.');
        }

        $log = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => $template,
            'recipient' => $invitation->sent_to,
            // The activation URL carries a working credential, so it never
            // reaches the log table.
            'payload' => collect($data)->except('activation_url')->all(),
            'status' => 'queued',
            'notifiable_type' => AccountInvitation::class,
            'notifiable_id' => $invitation->id,
        ]);

        $this->dispatchQontak($invitation, $data, $templateId, $log->ulid);

        return NotificationResult::ok(['mode' => 'queued']);
    }

    /** @param array<string, mixed> $data */
    private function dispatchQontak(AccountInvitation $invitation, array $data, string $templateId, ?string $logUlid): void
    {
        [$studentName, $unitLabel] = $this->childFor($invitation, $data);

        SendQontakTemplateMessage::dispatch(
            // Qontak wants 62..., the formatter gives 08... (same step as BillReminderSender).
            phone: '62'.substr((string) PhoneNumberFormatter::toWhatsAppFormat($invitation->sent_to), 1),
            toName: (string) ($data['guardian_name'] ?: 'Orang Tua/Wali'),
            templateId: $templateId,
            bodyValues: [(string) ($data['guardian_name'] ?: 'Orang Tua/Wali'), $studentName, $unitLabel, (string) $data['expires_at']],
            // The URL button's dynamic suffix: .../aktivasi?token={{1}}
            buttonValues: [(string) str($data['activation_url'])->after('token=')],
            notificationLogUlid: $logUlid,
        );
    }

    /**
     * The child the message names. An activation carries it from the PMB
     * handoff; a reset (or a resend of an old row) looks it up.
     *
     * @return array{0: string, 1: string}
     */
    private function childFor(AccountInvitation $invitation, array $data): array
    {
        if (! empty($data['student_name'])) {
            return [(string) $data['student_name'], (string) ($data['unit_label'] ?? 'YAPI Al Azhar')];
        }

        $students = \App\Models\Guardian::where('user_id', $invitation->user_id)->first()?->students()->with('schoolUnit')->get() ?? collect();

        return [
            $students->pluck('nama_lengkap')->implode(', ') ?: 'putra/putri Anda',
            $students->map(fn ($s) => $s->schoolUnit?->label)->filter()->unique()->implode(', ') ?: 'YAPI Al Azhar',
        ];
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
