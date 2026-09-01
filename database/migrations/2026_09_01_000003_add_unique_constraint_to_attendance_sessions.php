<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes a real race: AttendanceSessionService::open() checked for an
     * existing session then created one with no lock, so two teachers (or
     * one teacher's flaky double-tap) opening the same lesson period at once
     * could create two independent sessions - a student checked into the
     * first would show as unmarked on the second and could end up with both
     * a 'hadir' and an 'alpa' row for the same day. This constraint makes
     * that impossible at the DB level; the service now reopens the existing
     * row instead of ever creating a second one for the same (schedule, day).
     */
    public function up(): void
    {
        $this->mergeExistingDuplicates();

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->unique(['class_schedule_id', 'occurred_on']);
        });
    }

    /**
     * Defensive: if the race already produced duplicate sessions for some
     * (schedule, day) before this fix shipped, keep the one with the most
     * attendance_records (the one actually used), move any records from the
     * others onto it, then delete the now-empty duplicates - so the unique
     * constraint below can never fail to apply.
     */
    private function mergeExistingDuplicates(): void
    {
        $duplicateGroups = \DB::table('attendance_sessions')
            ->select('class_schedule_id', 'occurred_on')
            ->groupBy('class_schedule_id', 'occurred_on')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $sessions = \DB::table('attendance_sessions')
                ->where('class_schedule_id', $group->class_schedule_id)
                ->where('occurred_on', $group->occurred_on)
                ->get();

            $keep = $sessions->sortByDesc(fn ($s) => \DB::table('attendance_records')->where('attendance_session_id', $s->id)->count())->first();

            foreach ($sessions as $session) {
                if ($session->id === $keep->id) {
                    continue;
                }

                \DB::table('attendance_records')->where('attendance_session_id', $session->id)->update(['attendance_session_id' => $keep->id]);
                \DB::table('attendance_sessions')->where('id', $session->id)->delete();
            }
        }
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropUnique(['class_schedule_id', 'occurred_on']);
        });
    }
};
