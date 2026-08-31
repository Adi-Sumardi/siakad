<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The per-lesson-period attendance ledger. One row per (student,
         * session) mark, never edited once written - a correction is a revoke
         * with a reason, not an UPDATE or a DELETE (see docs/01-ARSITEKTUR.md
         * D6, same rule as point_records). No per-session-per-student unique
         * constraint at the DB level, same as point_records: "only one live
         * mark per session" is a service-layer rule
         * (AttendanceLedger::checkIn/hasCheckedIn), not a DB constraint.
         */
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('attendance_session_id')->constrained('attendance_sessions')->cascadeOnDelete();

            // Denormalized from session -> schedule -> classroom / date, so
            // reports group by classroom or date range without joining
            // through a schedule that can itself change.
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->date('occurred_on');

            // A term is never deleted while it still has ledger entries -
            // restrict, not cascade, same reasoning as point_records.term_id.
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();

            $table->enum('attendance_status', ['hadir', 'sakit', 'izin', 'alpa']);

            // Who created this row: the student themself via the public
            // check-in flow, or a teacher marking it manually for a student
            // who never checked in. `recorded_by` is null when self.
            $table->enum('source', ['self', 'guru']);
            $table->text('description')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('record_status', ['recorded', 'revoked'])->default('recorded');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'term_id', 'record_status']);
            $table->index('occurred_on');
            $table->index('attendance_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
