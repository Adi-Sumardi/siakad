<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\BillReminder;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Nudges families about a bill, three times at most and never twice on the
 * same beat.
 *
 * Reminder fatigue is the failure mode here: a family that gets four messages
 * about one SPP stops reading any of them, and then misses the one that
 * mattered. So the beats are few and deliberate - a week out to plan, the day
 * before to act, once after to notice - and each beat is recorded per channel
 * under a unique index so a job that runs twice cannot send twice.
 *
 * Channels are independent: email and WhatsApp each get the same data
 * snapshot and each own their own sent-row, log row, and failure - one being
 * down never silences the other. The in-app half of the story is the wali
 * navbar's bill bell, which derives from the live bills API rather than from
 * anything stored here, so it can never advertise a bill that has been paid.
 */
class BillReminderSender
{
    /** Which beat a bill is on today, or null if it is on none of them. */
    public const KINDS = ['h7', 'h1', 'overdue'];

    public function __construct(
        private MailGateway $mail,
        private WhatsAppGateway $whatsapp,
    ) {}

    public function kindFor(Bill $bill): ?string
    {
        if (! $bill->isOpen()) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($bill->due_date->startOfDay(), false);

        return match (true) {
            $days === 7 => 'h7',
            $days === 1 => 'h1',
            $days === -3 => 'overdue',
            default => null,
        };
    }

    /**
     * Sends one reminder beat through every channel the billing contact
     * actually has (email and WhatsApp are independent - one failing or
     * already-sent never blocks the other), or returns false when nothing
     * could be sent at all.
     */
    public function send(Bill $bill, string $kind): bool
    {
        // The freshest word on whether this bill still needs nagging. The
        // caller picked the bill from an open-bills query, but a payment can
        // land in the seconds between that SELECT and this dispatch - the
        // database decides, never the in-memory snapshot.
        $bill->refresh();

        if (! $bill->isOpen()) {
            Log::info('[Reminder] Bill dilewati - sudah lunas/ditutup', [
                'bill' => $bill->bill_number,
                'status' => $bill->status,
                'kind' => $kind,
            ]);

            return false;
        }

        $guardian = $this->billingContactFor($bill);

        if (! $guardian) {
            // Not an error worth failing the run over - a student whose billing
            // contact was never set is a data problem for an admin, and the
            // other families still need their reminders.
            Log::warning('[Reminder] Bill has no billing contact', ['bill' => $bill->bill_number]);

            return false;
        }

        $data = $this->buildData($bill, $guardian, $kind);
        $anySent = false;

        foreach ($this->channelsFor($guardian) as $channel => $to) {
            if (BillReminder::where('bill_id', $bill->id)->where('kind', $kind)->where('channel', $channel)->exists()) {
                Log::info('[Reminder] Dilewati - beat ini sudah pernah dikirim di channel ini', [
                    'bill' => $bill->bill_number,
                    'kind' => $kind,
                    'channel' => $channel,
                ]);

                continue;
            }

            // A gateway crash or a lost race on the unique index is one
            // channel's problem - the other channel still goes out.
            try {
                $result = $channel === 'email'
                    ? $this->mail->send($to, 'bill_reminder', $data)
                    : $this->whatsapp->sendMessage($to, $this->whatsappMessage($data));

                BillReminder::create([
                    'bill_id' => $bill->id,
                    'kind' => $kind,
                    'channel' => $channel,
                    'sent_to' => $to,
                    'sent_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                Log::info('[Reminder] Dilewati - channel ini sudah tercatat terkirim (race)', [
                    'bill' => $bill->bill_number,
                    'kind' => $kind,
                    'channel' => $channel,
                ]);

                continue;
            } catch (\Throwable $e) {
                Log::warning('[Reminder] Gagal menyiapkan pengiriman', [
                    'bill' => $bill->bill_number,
                    'kind' => $kind,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Recorded whether or not the gateway accepted it. A failed send
            // that the retry sweep picks up is better than a family messaged
            // twice because the first attempt was not written down.
            $this->log($bill, $channel, $to, $data, $result);

            $result->success
                ? Log::info('[Reminder] Terkirim', ['bill' => $bill->bill_number, 'kind' => $kind, 'channel' => $channel, 'to' => $to])
                : Log::warning('[Reminder] Gagal terkirim', ['bill' => $bill->bill_number, 'kind' => $kind, 'channel' => $channel, 'error' => $result->message]);

            $anySent = $anySent || $result->success;
        }

        return $anySent;
    }

    /**
     * The retry sweep's second chance for a reminder whose delivery failed.
     * Amounts are re-derived fresh (a partial payment since the failure
     * should not be nagged at the old figure), but only while the bill is
     * still open - a reminder for a bill that has since been paid is a
     * message about money that no longer exists.
     *
     * Deliberately no BillReminder row and no log() call: both were already
     * written by the original send, and the sweep updates the same
     * notification_logs row instead of adding one.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        $bill = $log->notifiable;
        $kind = $log->payload['kind'] ?? null;

        if (! $bill instanceof Bill || ! in_array($kind, self::KINDS, true)) {
            return NotificationResult::fail('Tagihan atau jenis pengingat sudah tidak ada.');
        }

        // Same rule as send(): the retry may run hours after the failure, so
        // the status is re-read from the database, not trusted from the row.
        $bill->refresh();

        if (! $bill->isOpen()) {
            return NotificationResult::fail('Tagihan sudah tidak terbuka (lunas/dibatalkan).');
        }

        $guardian = $this->billingContactFor($bill);

        if (! $guardian) {
            return NotificationResult::fail('Tagihan tanpa kontak penagihan.');
        }

        $data = $this->buildData($bill, $guardian, $kind);

        return $log->channel === 'email'
            ? $this->mail->send($log->recipient, 'bill_reminder', $data)
            : $this->whatsapp->sendMessage($log->recipient, $this->whatsappMessage($data));
    }

    /**
     * The guardian marked as the billing contact, falling back to the primary
     * one - a bill with no marked contact should still reach somebody.
     */
    private function billingContactFor(Bill $bill): ?Guardian
    {
        $guardians = $bill->student->guardians;

        return $guardians->firstWhere('pivot.is_billing_contact', true)
            ?? $guardians->firstWhere('pivot.is_primary', true)
            ?? $guardians->first();
    }

    /**
     * Every channel the contact can actually be reached on: email when an
     * address exists, WhatsApp when a phone number does. A contact with both
     * gets both; one with neither yields nothing and the bill is skipped.
     *
     * @return array<string, string>
     */
    private function channelsFor(Guardian $guardian): array
    {
        $channels = [];

        if (filled($guardian->email)) {
            $channels['email'] = $guardian->email;
        }

        if (filled($guardian->no_hp)) {
            $channels['whatsapp'] = $guardian->no_hp;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    private function buildData(Bill $bill, Guardian $guardian, string $kind): array
    {
        return [
            'guardian_name' => $guardian->nama,
            'student_name' => $bill->student->nama_panggilan ?: $bill->student->nama_lengkap,
            'description' => $bill->description,
            'amount' => number_format((float) $bill->remaining_amount, 0, ',', '.'),
            'due_date' => $bill->due_date->translatedFormat('d F Y'),
            'kind' => $kind,
        ];
    }

    private function whatsappMessage(array $data): string
    {
        $lead = match ($data['kind']) {
            'h7' => "Pengingat: {$data['description']} untuk {$data['student_name']} jatuh tempo "
                ."{$data['due_date']} (7 hari lagi).",
            'h1' => "Besok jatuh tempo: {$data['description']} untuk {$data['student_name']}.",
            default => "{$data['description']} untuk {$data['student_name']} sudah lewat jatuh tempo "
                ."({$data['due_date']}).",
        };

        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            .$lead."\n\n"
            ."Sisa tagihan: Rp {$data['amount']}\n\n"
            .'Pembayaran bisa dilakukan lewat aplikasi sekolah. Abaikan pesan ini bila sudah dibayar.';
    }

    private function log(Bill $bill, string $channel, string $to, array $data, NotificationResult $result): void
    {
        NotificationLog::create([
            'channel' => $channel,
            'template' => 'bill_reminder',
            'recipient' => $to,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);
    }
}
