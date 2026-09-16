<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (php artisan schedule:work). Fase 2 adds the
| SPP generator, the overdue sweep, and payment reminders here.
|
*/

// SPP for the month. Early on the 1st, before anyone is looking at the app, and
// idempotent - the dedup_key means a second firing issues nothing.
Schedule::command('bills:generate --type=spp')
    ->monthlyOn(1, '00:30')
    ->name('generate-monthly-spp')
    ->withoutOverlapping()
    ->description('Terbitkan SPP bulan berjalan untuk siswa aktif');

// After the generator, so a bill issued today is never marked late on the same
// run that created it.
Schedule::command('bills:mark-overdue')
    ->dailyAt('01:00')
    ->name('mark-overdue-bills')
    ->description('Tandai tagihan yang lewat jatuh tempo');

// Morning, so a nudge lands when someone can act on it rather than at 1am. The
// unique index on (bill_id, kind) is what keeps a second firing silent, not the
// schedule itself.
Schedule::command('bills:send-reminders')
    ->dailyAt('07:00')
    ->name('send-bill-reminders')
    ->withoutOverlapping()
    ->description('Pengingat jatuh tempo H-7, H-1, dan H+3');

// Morning, after the day has started - a family should not open their phone
// before dawn to a notice about their child's points. Idempotent: see the
// unique row in point_threshold_notifications, not this schedule, for why a
// second run sends nothing.
Schedule::command('points:evaluate-thresholds')
    ->dailyAt('06:30')
    ->name('evaluate-point-thresholds')
    ->withoutOverlapping()
    ->description('Notifikasi wali murid saat saldo poin melewati ambang');

// Keeps the unit master in step with PMB. Daily is often enough: units change
// once a year at most, but a stale code means a handoff for a new unit fails
// with "Unit tidak dikenal" until someone notices.
Schedule::command('units:sync')
    ->dailyAt('03:00')
    ->name('sync-school-units')
    ->withoutOverlapping()
    ->description('Sinkronkan master unit dari PMB');

// Polling status Virtual Account Bank Muamalat (e-SPP)
Schedule::command('payments:poll-billing-va')
    ->everyTwoMinutes()
    ->name('poll-billing-va-payments')
    ->withoutOverlapping()
    ->description('Periksa status pelunasan Virtual Account Bank Muamalat (e-SPP)');

// The daily attendance heartbeat (T14): opens each unit's masuk/pulang
// sessions from its own settings and closes windows that have ended -
// closing a morning window sweeps unmarked students into alpa + WhatsApp.
// Idempotent both ways: the (unit, date, type) unique stops double opens,
// the status column stops double closes. Every few minutes rather than
// fixed hours so a mid-morning settings edit applies the same day.
Schedule::command('attendance:daily-sweep')
    ->everyFiveMinutes()
    ->name('sweep-daily-attendance')
    ->withoutOverlapping()
    ->description('Buka/tutup sesi presensi harian sesuai setting unit');

// The consumer half of notification_logs (audit C2): rows a Sendago send
// refused get read back and retried, bounded at three attempts over 24 hours
// - beyond that, the failure is a human's problem and the exhaustion digest
// in the log plus the dashboard card are how a human notices. OTP rows are
// excluded on purpose; see the command's docblock.
Schedule::command('notifications:retry-failed')
    ->everyThirtyMinutes()
    ->name('retry-failed-notifications')
    ->withoutOverlapping()
    ->description('Coba ulang notifikasi email/WhatsApp yang gagal terkirim');

