<?php

namespace App\Services\Points;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\PointThreshold;
use App\Models\PointThresholdNotification;
use App\Models\Student;
use App\Models\Term;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * Tells a guardian once, not every day, that their child's balance has moved
 * into a band that matters.
 *
 * "Once per threshold per term" is the whole design: point_threshold_notifications
 * carries a unique (student_id, term_id, point_threshold_id) row, so a balance
 * that sits in "Peringatan 1" for three weeks - or drifts back into it after a
 * brief recovery - triggers exactly one email for that band. Dropping further,
 * into a worse band, is a different threshold_id and does notify again; that is
 * the one case a family genuinely needs to hear about twice.
 */
class PointThresholdNotifier
{
    public function __construct(
        private PointLedger $ledger,
        private MailGateway $mail,
        private WhatsAppGateway $whatsapp,
    ) {}

    /** Sends the notification if this student has crossed into a fresh band, or returns false. */
    public function evaluate(Student $student, Term $term): bool
    {
        $balance = $this->ledger->balance($student, $term);
        $threshold = PointThreshold::forBalance($balance, $student->school_unit_id);

        if (! $threshold || ! $threshold->notify_guardian) {
            return false;
        }

        $alreadySent = PointThresholdNotification::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->where('point_threshold_id', $threshold->id)
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $guardian = $this->primaryGuardianFor($student);

        if (! $guardian) {
            Log::warning('[PointThreshold] Student has no guardian to notify', ['student' => $student->nama_lengkap]);

            return false;
        }

        $channel = $guardian->email ? 'email' : 'whatsapp';
        $to = $guardian->email ?: (string) $guardian->no_hp;

        if (! $to) {
            return false;
        }

        $data = [
            'guardian_name' => $guardian->nama,
            'student_name' => $student->nama_panggilan ?: $student->nama_lengkap,
            'balance' => (string) $balance,
            'label' => $threshold->label,
            'action' => (string) $threshold->action,
        ];

        $result = $channel === 'email'
            ? $this->mail->send($to, 'point_threshold', $data)
            : $this->whatsapp->sendMessage($to, $this->whatsappMessage($data));

        // Recorded before checking $result->success: a failed send that gets
        // retried tomorrow by the same daily job would otherwise need its own
        // separate retry bookkeeping. Simpler to log the failure and let a
        // human resend from the admin screen if it matters.
        PointThresholdNotification::create([
            'student_id' => $student->id,
            'term_id' => $term->id,
            'point_threshold_id' => $threshold->id,
            'balance_at_notification' => $balance,
            'notified_at' => now(),
        ]);

        $this->log($student, $channel, $to, $data, $result);

        return $result->success;
    }

    private function primaryGuardianFor(Student $student): ?Guardian
    {
        $guardians = $student->guardians;

        return $guardians->firstWhere('pivot.is_primary', true) ?? $guardians->first();
    }

    private function whatsappMessage(array $data): string
    {
        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            ."Poin {$data['student_name']} saat ini {$data['balance']} ({$data['label']}).\n\n"
            .($data['action'] !== '' ? "{$data['action']}\n\n" : '')
            .'Rincian dapat dilihat di aplikasi sekolah.';
    }

    private function log(Student $student, string $channel, string $to, array $data, NotificationResult $result): void
    {
        NotificationLog::create([
            'channel' => $channel,
            'template' => 'point_threshold',
            'recipient' => $to,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Student::class,
            'notifiable_id' => $student->id,
        ]);
    }
}
