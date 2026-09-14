<?php

namespace App\Services\Attendance;

use App\Models\DailyRecord;
use App\Models\NotificationLog;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * The WhatsApp leg of the daily attendance layer (DESAIN-PRESENSI-HARIAN.md
 * §5F). Three message kinds - masuk, pulang, and the absent alert that fires
 * when the morning window closes on an unmarked student - each behind its own
 * on/off valve in the unit's settings, because a per-event ping for every
 * student twice a day is the first thing a campus will want to silence.
 *
 * Deduplication is the notification_logs row, not a promise that a job runs
 * once: the scheduler may fire twice, a correction may re-mark a student, and
 * neither may produce a second WhatsApp for the same record. A failed send
 * still writes its row - same policy as PointThresholdNotifier: log the
 * failure, let a human resend, never an automatic retry (open question §4
 * no. 3 in PROGRESS-MAGANG, mentor domain).
 */
class DailyAttendanceNotifier
{
    public function __construct(private WhatsAppGateway $whatsapp) {}

    /** "Ananda has arrived / has left" for a freshly written hadir-flavoured record. */
    public function recorded(DailyRecord $record): void
    {
        $session = $record->dailySession;
        $setting = $session?->schoolUnit?->dailyAttendanceSetting;

        if (! $setting || ($session->type === 'masuk' ? ! $setting->notify_masuk : ! $setting->notify_pulang)) {
            return;
        }

        if (! $record->isActive()) {
            return;
        }

        $this->send($record, 'daily_'.$session->type, $this->arrivalMessage($record));
    }

    /** The end-of-window alert for a student the whole morning missed. */
    public function absent(DailyRecord $record): void
    {
        $setting = $record->dailySession?->schoolUnit?->dailyAttendanceSetting;

        if (! $setting || ! $setting->notify_absent) {
            return;
        }

        $this->send($record, 'daily_absent', $this->absentMessage($record));
    }

    private function send(DailyRecord $record, string $template, string $body): void
    {
        if (NotificationLog::query()
            ->where('template', $template)
            ->where('notifiable_type', DailyRecord::class)
            ->where('notifiable_id', $record->id)
            ->exists()) {
            return;
        }

        $student = $record->student;
        $guardians = $student?->guardians->filter(fn ($g) => trim((string) $g->no_hp) !== '');

        if ($guardians->isEmpty()) {
            Log::warning('[DailyAttendance] Student has no guardian with a phone number to notify', [
                'student' => $student?->nama_lengkap,
                'template' => $template,
            ]);

            return;
        }

        foreach ($guardians as $guardian) {
            $to = (string) $guardian->no_hp;
            $message = str_replace('{wali}', (string) $guardian->nama, $body);

            $result = $this->whatsapp->sendMessage($to, $message);

            NotificationLog::create([
                'channel' => 'whatsapp',
                'template' => $template,
                'recipient' => $to,
                'payload' => ['student' => $student?->nama_lengkap, 'body' => $message],
                'status' => $result->success ? 'sent' : 'failed',
                'error' => $result->success ? null : $result->message,
                'sent_at' => $result->success ? now() : null,
                'notifiable_type' => DailyRecord::class,
                'notifiable_id' => $record->id,
            ]);
        }
    }

    private function arrivalMessage(DailyRecord $record): string
    {
        $session = $record->dailySession;
        $student = $record->student;
        $nama = $student?->nama_panggilan ?: $student?->nama_lengkap;
        $jam = ($record->checked_in_at ?: $record->created_at)?->format('H:i');

        $late = '';
        if ($record->is_late && $session->late_after && $record->checked_in_at) {
            $batas = $record->checked_in_at->copy()->setTimeFromTimeString($session->late_after->format('H:i:s'));
            $menit = max(1, abs((int) $record->checked_in_at->diffInMinutes($batas)));
            $late = " — terlambat {$menit} menit.";
        } elseif ($record->is_late) {
            $late = ' — tercatat terlambat.';
        }

        $verb = $session->type === 'masuk' ? 'MASUK di '.$session->schoolUnit?->label : 'PULANG';

        return "Assalamu'alaikum {wali},\n\n"
            ."Ananda {$nama} tercatat {$verb} pukul {$jam} WIB{$late}\n\n"
            .'Rincian dapat dilihat di aplikasi sekolah.';
    }

    private function absentMessage(DailyRecord $record): string
    {
        $student = $record->student;
        $nama = $student?->nama_panggilan ?: $student?->nama_lengkap;
        $jam = $record->dailySession?->closes_at?->format('H:i');

        return "Assalamu'alaikum {wali},\n\n"
            ."Ananda {$nama} tidak tercatat hadir di ".$record->dailySession?->schoolUnit?->label
            ." hingga pukul {$jam} WIB.\n\n"
            ."Bila Ananda seharusnya hadir, mohon hubungi pihak sekolah.\n\n"
            .'Rincian dapat dilihat di aplikasi sekolah.';
    }
}
