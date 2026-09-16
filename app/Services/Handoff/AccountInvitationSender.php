<?php

namespace App\Services\Handoff;

use App\Models\AccountInvitation;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Support\Facades\DB;

/**
 * Issues the single-use link a guardian uses to set their own password, and
 * delivers it over whichever channel that guardian can actually receive.
 */
class AccountInvitationSender
{
    public function __construct(
        private MailGateway $mail,
        private WhatsAppGateway $whatsapp,
    ) {}

    /**
     * Creates an invitation for $user and sends it.
     *
     * Any earlier unused invitation for the same purpose is consumed first, so
     * a resend leaves exactly one working link - otherwise "kirim ulang" would
     * quietly widen the window by leaving both tokens alive.
     *
     * @param  array<string, string>  $context  values the email template needs
     */
    public function send(User $user, array $context, ?User $actor = null): NotificationResult
    {
        $plainToken = AccountInvitation::generateToken();

        $invitation = DB::transaction(function () use ($user, $plainToken, $actor) {
            $previous = AccountInvitation::where('user_id', $user->id)
                ->where('purpose', 'activation')
                ->whereNull('used_at')
                ->get();

            $sentCount = 1;

            foreach ($previous as $old) {
                $sentCount = max($sentCount, $old->sent_count + 1);
                $old->markUsed();
            }

            // WhatsApp only when there is no email: an address that exists is
            // the channel that carries a clickable link reliably.
            $channel = $user->email ? 'email' : 'whatsapp';

            return AccountInvitation::create([
                'user_id' => $user->id,
                'token_hash' => AccountInvitation::hashToken($plainToken),
                'channel' => $channel,
                'sent_to' => $user->email ?: (string) $user->phone,
                'purpose' => 'activation',
                'expires_at' => now()->addDays(AccountInvitation::TTL_DAYS),
                'sent_count' => $sentCount,
                'last_sent_at' => now(),
                'created_by' => $actor?->id,
            ]);
        });

        $url = rtrim((string) config('app.frontend_url'), '/').'/aktivasi?token='.$plainToken;

        $data = array_merge($context, [
            'activation_url' => $url,
            'login_identifier' => $invitation->sent_to,
            'expires_at' => $invitation->expires_at->translatedFormat('d F Y'),
            'guardian_name' => $user->name,
        ]);

        return $invitation->channel === 'email'
            ? $this->deliverEmail($invitation, $data)
            : $this->deliverWhatsApp($invitation, $data);
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

        $data = array_merge($log->payload ?? [], [
            'activation_url' => rtrim((string) config('app.frontend_url'), '/').'/aktivasi?token='.$plainToken,
            'login_identifier' => $invitation->sent_to,
            'expires_at' => $invitation->expires_at->translatedFormat('d F Y'),
            'guardian_name' => $invitation->user?->name ?? (string) ($log->payload['guardian_name'] ?? ''),
        ]);

        return $log->channel === 'email'
            ? $this->mail->send($log->recipient, 'school_account_invite', $data)
            : $this->whatsapp->sendMessage($log->recipient, $this->renderWhatsAppMessage($data));
    }

    private function deliverEmail(AccountInvitation $invitation, array $data): NotificationResult
    {
        $result = $this->mail->send($invitation->sent_to, 'school_account_invite', $data);

        $this->log($invitation, 'email', 'school_account_invite', $data, $result);

        return $result;
    }

    private function deliverWhatsApp(AccountInvitation $invitation, array $data): NotificationResult
    {
        $result = $this->whatsapp->sendMessage($invitation->sent_to, $this->renderWhatsAppMessage($data));

        $this->log($invitation, 'whatsapp', 'school_account_invite', $data, $result);

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function renderWhatsAppMessage(array $data): string
    {
        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            ."Uang pangkal {$data['student_name']} sudah lunas dan akun aplikasi sekolah sudah kami buatkan.\n\n"
            ."Aktifkan akun serta tentukan kata sandi di tautan berikut:\n{$data['activation_url']}\n\n"
            ."Tautan berlaku sampai {$data['expires_at']}.";
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
