<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One live mark per (session, student) on the LESSON layer - the same
 * contract the daily layer has enforced since 2026_09_12 (audit T45 / DESAIN
 * §5E). Without it, a student scan racing the teacher's "Selesaikan Presensi"
 * (recordBulk ran outside the session lock checkIn() takes) could leave two
 * live rows - one hadir, one alpa - that the roster then showed arbitrarily
 * and revoking one left the other alive.
 *
 * Pre-pass merges any duplicates the race already produced: newest row per
 * (session, student) survives, the rest are revoked (ledger discipline -
 * never delete), mirroring 2026_09_01_000003's shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dupes = DB::table('attendance_records')
            ->select('attendance_session_id', 'student_id')
            ->where('record_status', 'recorded')
            ->groupBy('attendance_session_id', 'student_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            $ids = DB::table('attendance_records')
                ->where('attendance_session_id', $dupe->attendance_session_id)
                ->where('student_id', $dupe->student_id)
                ->where('record_status', 'recorded')
                ->orderByDesc('id')
                ->pluck('id');

            DB::table('attendance_records')
                ->whereIn('id', $ids->skip(1))
                ->update([
                    'record_status' => 'revoked',
                    'revoke_reason' => 'Gabungan otomatis: tanda ganda hasil balapan scan vs penyelesaian guru (migrasi T45).',
                    'revoked_at' => now(),
                ]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX attendance_records_one_live_mark_per_student'
            .' ON attendance_records (attendance_session_id, student_id)'
            .' WHERE record_status = \'recorded\''
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_records_one_live_mark_per_student');
    }
};
