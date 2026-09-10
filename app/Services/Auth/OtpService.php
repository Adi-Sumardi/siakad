<?php

namespace App\Services\Auth;

use App\Jobs\SendWhatsAppMessage;
use App\Models\LoginOtp;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\PhoneNumberFormatter;
use App\Services\Security\FieldEncrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues and checks the one-time codes guardians sign in with.
 *
 * The channel is not a choice the guardian makes on a separate screen - it
 * follows from what they typed. An email address gets an emailed code, a phone
 * number gets one over WhatsApp. That keeps the login screen a single field.
 */
class OtpService
{
    public function __construct(
        private MailGateway $mail,
        private FieldEncrypter $encrypter,
    ) {}

    /**
     * @return array{otp: LoginOtp, sent: NotificationResult}
     */
    public function issue(User $user, string $identifier, ?string $ip = null): array
    {
        $channel = $this->channelFor($identifier);

        // An unconsumed code from a moment ago means this is a resend; refuse
        // until the cooldown passes so the endpoint cannot be used to bombard
        // someone's phone.
        $pending = LoginOtp::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($pending && ($wait = $pending->secondsUntilResendAllowed()) > 0) {
            throw new OtpThrottled($wait);
        }

        $code = $this->generateCode();

        $otp = DB::transaction(function () use ($user, $identifier, $channel, $code, $ip) {
            // Exactly one live code per account: issuing a new one retires the
            // old, so a guardian who requested twice is not left guessing which
            // of two messages is still valid.
            LoginOtp::where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return LoginOtp::create([
                'user_id' => $user->id,
                'identifier' => $identifier,
                'channel' => $channel,
                'code_hash' => LoginOtp::hashCode($code),
                'expires_at' => now()->addMinutes(LoginOtp::TTL_MINUTES),
                'ip_address' => $ip,
            ]);
        });

        // WhatsApp logs itself - queuing (see queueWhatsApp()) means the
        // eventual sent/failed status can no longer be known synchronously
        // here, so there is nothing left for the generic log() call below to
        // record honestly for that channel.
        if ($channel === 'email') {
            $sent = $this->sendEmail($user, $identifier, $code);
            $this->log($otp, $channel, $identifier, $sent);
        } else {
            $sent = $this->queueWhatsApp($otp, $identifier, $code);
        }

        return ['otp' => $otp, 'sent' => $sent];
    }

    /**
     * Returns the user when the code is right, null otherwise.
     *
     * Every failed guess is counted against the code itself, not just against
     * the IP - an attacker rotating addresses still only gets MAX_ATTEMPTS per
     * code.
     */
    public function verify(string $identifier, string $code): ?User
    {
        $otp = LoginOtp::where('identifier', $identifier)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return null;
        }

        if (! hash_equals($otp->code_hash, LoginOtp::hashCode($code))) {
            $otp->increment('attempts');

            return null;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return $otp->user;
    }

    /** Finds the account an identifier belongs to; phone goes through the blind index. */
    public function findUser(string $identifier): ?User
    {
        if ($this->channelFor($identifier) === 'email') {
            return User::where('email', $identifier)->first();
        }

        return User::where('phone_hash', $this->encrypter->blindIndex($identifier))->first();
    }

    public function channelFor(string $identifier): string
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'whatsapp';
    }

    /**
     * Normalises before anything is looked up or stored, so "0812…" and
     * "+62812…" resolve to one account and one throttle bucket.
     */
    public function normalise(string $identifier): string
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return mb_strtolower($identifier);
        }

        return PhoneNumberFormatter::toWhatsAppFormat($identifier) ?: $identifier;
    }

    /**
     * random_int, not rand(): this is a credential, and a predictable sequence
     * would let anyone who saw one code compute the next.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendEmail(User $user, string $to, string $code): NotificationResult
    {
        return $this->mail->send($to, 'login_otp', [
            'name' => $user->name,
            'code' => $code,
            'minutes' => (string) LoginOtp::TTL_MINUTES,
        ]);
    }

    /**
     * Queues the WhatsApp send instead of making it inline - see
     * App\Jobs\SendWhatsAppMessage for why. The NotificationLog row is
     * created here, up front, as 'queued' (a real state in the status enum,
     * not a workaround) so there is still an immediate record even before
     * the job runs; the job updates this same row to sent/failed once it
     * actually attempts delivery.
     */
    private function queueWhatsApp(LoginOtp $otp, string $phone, string $code): NotificationResult
    {
        $message = "Kode masuk Siakad YAPI Anda: *{$code}*\n\n"
            ."Berlaku ".LoginOtp::TTL_MINUTES." menit. Jangan berikan kode ini kepada siapa pun, "
            .'termasuk yang mengaku dari sekolah.';

        $log = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'login_otp',
            'recipient' => $phone,
            // The code is the credential; it never reaches the log table.
            'payload' => ['otp_ulid' => $otp->ulid],
            'status' => 'queued',
            'notifiable_type' => LoginOtp::class,
            'notifiable_id' => $otp->id,
        ]);

        SendWhatsAppMessage::dispatch($phone, $message, $log->ulid);

        return NotificationResult::ok(['mode' => 'queued']);
    }

    private function log(LoginOtp $otp, string $channel, string $recipient, NotificationResult $result): void
    {
        NotificationLog::create([
            'channel' => $channel,
            'template' => 'login_otp',
            'recipient' => $recipient,
            // The code is the credential; it never reaches the log table.
            'payload' => ['otp_ulid' => $otp->ulid],
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => LoginOtp::class,
            'notifiable_id' => $otp->id,
        ]);
    }
}

/** Raised when a resend is asked for before the cooldown has elapsed. */
class OtpThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Tunggu {$retryAfterSeconds} detik sebelum meminta kode lagi.");
    }
}
