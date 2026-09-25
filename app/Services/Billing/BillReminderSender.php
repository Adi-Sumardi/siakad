<?php

namespace App\Services\Billing;

use App\Jobs\SendQontakTemplateMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Bill;
use App\Models\BillReminder;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Payment\BillingApiGateway;
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
 * down never silences the other. WhatsApp is always queued rather than sent
 * inline: due dates cluster on the same day of the month, so this daily sweep
 * is exactly the burst an unthrottled gateway cannot absorb. SPP goes out
 * through Qontak's approved 'reminder_spp_school' template (an official
 * Business line can only send a template to a cold number); every other fee
 * type stays on the free-text Sendago path until it gets its own template.
 */
class BillReminderSender
{
    /** Which beat a bill is on today, or null if it is on none of them. */
    public const KINDS = ['h7', 'h1', 'overdue'];

    public function __construct(
        private MailGateway $mail,
        private WhatsAppGateway $whatsapp,
        private BillingApiGateway $billingApi,
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
     * actually has, or returns false when nothing could be sent at all.
     */
    public function send(Bill $bill, string $kind): bool
    {
        // A payment can land between the scheduler's SELECT and this send -
        // the database decides, never the in-memory snapshot.
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

            try {
                // Claimed before sending, not after: a lost race on the unique
                // index then means "someone else is sending this", never "it
                // was already sent twice".
                BillReminder::create([
                    'bill_id' => $bill->id,
                    'kind' => $kind,
                    'channel' => $channel,
                    'sent_to' => $to,
                    'sent_at' => now(),
                ]);

                $result = $channel === 'email'
                    ? $this->sendEmail($bill, $to, $data)
                    : $this->queueWhatsApp($bill, $guardian, $to, $data);
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

            $result->success
                ? Log::info('[Reminder] Terkirim/diantrikan', ['bill' => $bill->bill_number, 'kind' => $kind, 'channel' => $channel])
                : Log::warning('[Reminder] Gagal terkirim', ['bill' => $bill->bill_number, 'kind' => $kind, 'channel' => $channel, 'error' => $result->message]);

            $anySent = $anySent || $result->success;
        }

        return $anySent;
    }

    /**
     * The retry sweep's second chance for a reminder whose delivery failed -
     * sent inline here, since the sweep is already bounded and paced. Amounts
     * are re-derived fresh, and only while the bill is still open.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        // The Qontak template lane (audit T43, rebuilt audit T59): values are
        // rebuilt FRESH from the bill, not replayed from the row's frozen
        // payload - a resend can happen hours after the failure, by which time
        // the VA pair may have expired at e-SPP or the bill may have been
        // part-paid. Sending the frozen payload would hand the family a dead
        // VA number (or a paid bill's old balance), so the same guard applies
        // as the non-SPP lane below: still open, or no send at all.
        if ($log->template === 'reminder_spp') {
            $templateId = config('services.qontak.spp_reminder_template_id');

            if (blank($templateId) || ! $log->recipient) {
                return NotificationResult::fail('Template reminder SPP belum dikonfigurasi atau penerima kosong.');
            }

            $bill = $log->notifiable;

            if (! $bill instanceof Bill) {
                return NotificationResult::fail('Tagihan untuk pengingat ini sudah tidak ada.');
            }

            $bill->refresh();

            if (! $bill->isOpen()) {
                return NotificationResult::fail('Tagihan sudah tidak terbuka (lunas/dibatalkan) - pengingat tidak lagi relevan.');
            }

            $guardian = $this->billingContactFor($bill);

            if (! $guardian) {
                return NotificationResult::fail('Tagihan tanpa kontak penagihan.');
            }

            $built = $this->buildSppReminderValues($bill, $guardian);

            if (isset($built['error'])) {
                return NotificationResult::fail($built['error']);
            }

            // Same row, fresh payload - the delivery still owns exactly one
            // line of history; applyResult() flips it to queued and counts
            // the attempt.
            $log->update([
                'payload' => $built['payload'],
            ]);

            SendQontakTemplateMessage::dispatch(
                phone: $log->recipient,
                toName: $guardian->nama ?: 'Orang Tua/Wali',
                templateId: (string) $templateId,
                bodyValues: $built['values'],
                notificationLogUlid: $log->ulid,
            );

            return NotificationResult::ok(['mode' => 'queued']);
        }

        $bill = $log->notifiable;
        $kind = $log->payload['kind'] ?? null;

        if (! $bill instanceof Bill || ! in_array($kind, self::KINDS, true)) {
            return NotificationResult::fail('Tagihan atau jenis pengingat sudah tidak ada.');
        }

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

    private function billingContactFor(Bill $bill): ?Guardian
    {
        return $bill->student->billingContact();
    }

    /**
     * Every channel the contact can actually be reached on.
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
            $channels['whatsapp'] = (string) $guardian->no_hp;
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

    private function sendEmail(Bill $bill, string $to, array $data): NotificationResult
    {
        $result = $this->mail->send($to, 'bill_reminder', $data);

        // Recorded whether or not the gateway accepted it - the retry sweep
        // picks up a failed row; an unrecorded one would be sent twice.
        NotificationLog::create([
            'channel' => 'email',
            'template' => 'bill_reminder',
            'recipient' => $to,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        return $result;
    }

    private function queueWhatsApp(Bill $bill, Guardian $guardian, string $phone, array $data): NotificationResult
    {
        if ($bill->feeType?->code === 'spp' && filled(config('services.qontak.spp_reminder_template_id'))) {
            return $this->queueSppReminderTemplate($bill, $guardian, $phone);
        }

        $log = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'bill_reminder',
            'recipient' => $phone,
            'payload' => $data,
            'status' => 'queued',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        // A jittered head start instead of a thundering herd: the H-7 beat
        // queues one job per student at once, and dumping all of them on the
        // worker in the same second is what makes the limiter hold (and, pre-
        // T42, kill) the tail of the burst. Five minutes of spread matches
        // the 60/min limiter for bursts up to ~300 messages.
        SendWhatsAppMessage::dispatch($phone, $this->whatsappMessage($data), $log->ulid)
            ->delay(now()->addSeconds(random_int(0, 300)));

        return NotificationResult::ok(['mode' => 'queued']);
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

    /**
     * Registers both banks' VA for this bill (idempotent - see
     * BillingApiGateway::ensureReminderVaPair()) and queues the approved
     * template with both numbers. Body variables, in order: nama anak, bulan
     * tagihan, jumlah, VA Muamalat, kode bayar BSI (the VA minus its
     * 4-digit institution code - 7895 for SPP).
     */
    private function queueSppReminderTemplate(Bill $bill, Guardian $guardian, string $phone): NotificationResult
    {
        $built = $this->buildSppReminderValues($bill, $guardian);

        if (isset($built['error'])) {
            // The beat must not burn silently (audit T59): the BillReminder
            // claim above already marks this (bill, kind, channel) as done,
            // so without a row here the reminder was never sent, never
            // retried (the sweep only reads notification_logs), and never
            // shown on the failure dashboard - e-SPP being down for half an
            // hour at sweep time erased the whole cohort's reminder. A
            // failed row re-enters through the existing retry lane, whose
            // resend() rebuilds the VA pair fresh once e-SPP is back.
            NotificationLog::create([
                'channel' => 'whatsapp',
                'template' => 'reminder_spp',
                'recipient' => $phone,
                'payload' => [
                    'student_name' => $bill->student->nama_lengkap,
                    'note' => 'Pendaftaran VA gagal saat antre - nilai dibangun ulang saat dikirim kembali.',
                ],
                'status' => 'failed',
                'error' => $built['error'],
                'notifiable_type' => Bill::class,
                'notifiable_id' => $bill->id,
            ]);

            return NotificationResult::fail($built['error']);
        }

        // The row rides the job (audit T43): SendQontakTemplateMessage now
        // takes the log ulid and flips this row to sent/failed itself - a
        // Qontak outage no longer leaves reminders 'queued' forever where
        // no screen could see them.
        $log = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => $phone,
            'payload' => $built['payload'],
            'status' => 'queued',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        SendQontakTemplateMessage::dispatch(
            // The gateway owns the 62-prefix conversion now (audit T42-b) -
            // callers pass the app's stored 08xx form.
            phone: $phone,
            toName: $guardian->nama ?: 'Orang Tua/Wali',
            templateId: config('services.qontak.spp_reminder_template_id'),
            bodyValues: $built['values'],
            notificationLogUlid: $log->ulid,
        )->delay(now()->addSeconds(random_int(0, 300)));

        return NotificationResult::ok(['mode' => 'queued']);
    }

    /**
     * Everything a reminder_spp delivery needs, derived from the bill as it
     * stands right now. Shared by the queue-time send and the resend lane so
     * the two can never drift - a resend hours later must reflect the bill's
     * CURRENT balance and a freshly registered VA pair, not queue-time
     * snapshots (audit T59).
     *
     * @return array{error: string}|array{values: list<string>, payload: array<string, string>}
     */
    private function buildSppReminderValues(Bill $bill, Guardian $guardian): array
    {
        try {
            $va = $this->billingApi->ensureReminderVaPair($bill, $guardian);
        } catch (\Throwable $e) {
            Log::warning('[BillReminderSender] Failed to register VA pair for SPP reminder', [
                'bill' => $bill->bill_number,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Gagal mendaftarkan Virtual Account: '.$e->getMessage()];
        }

        $muamalatVa = $va['muamalat']['va_number'] ?? '';
        $bsiVa = $va['bsi']['va_number'] ?? '';
        $bsiPaymentCode = mb_strlen($bsiVa) > 4 ? mb_substr($bsiVa, 4) : $bsiVa;
        // The bill's OWN period month when it has one (audit T55-b):
        // issued_at is a printing date, so a late-issued SPP reminded as
        // the wrong month. Year follows the printing date, correct for
        // every month of the academic year.
        $period = $bill->period_month
            ? \Illuminate\Support\Carbon::create(($bill->issued_at ?? $bill->due_date)->year, (int) $bill->period_month, 1)->translatedFormat('F Y')
            : ($bill->issued_at?->translatedFormat('F Y') ?? $bill->due_date->translatedFormat('F Y'));
        $amount = number_format((float) $bill->remaining_amount, 0, ',', '.');

        return [
            'values' => [$bill->student->nama_lengkap, $period, $amount, $muamalatVa, $bsiPaymentCode],
            'payload' => [
                'student_name' => $bill->student->nama_lengkap,
                'period' => $period,
                'amount' => $amount,
                'va_muamalat' => $muamalatVa,
                'va_bsi_payment_code' => $bsiPaymentCode,
            ],
        ];
    }
}
