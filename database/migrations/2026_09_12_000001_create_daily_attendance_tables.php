<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The daily attendance layer (DESAIN-PRESENSI-HARIAN.md, T14): "did the
     * child come to school today" for EVERY unit, as opposed to the existing
     * per-lesson attendance_sessions/attendance_records pair which only runs
     * where subject teachers exist (SMP/SMA). One mark per student per day per
     * type (masuk/pulang), corrections are revokes with a reason, never
     * deletes - the same ledger contract as attendance_records (D6).
     *
     * All datetime columns store Jakarta WALL-CLOCK values (the date part is
     * the Jakarta calendar date), and every comparison in the service layer
     * runs against Carbon::now('Asia/Jakarta') - never a bare now(), which
     * would report the wrong day before 07:00 WIB while app.timezone is still
     * UTC (switching it is a mentor-domain change, see PROGRESS-MAGANG §3.1).
     */
    public function up(): void
    {
        Schema::create('daily_settings', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            // One row per unit - the unit owns its own bells and gates.
            $table->foreignId('school_unit_id')->unique()->constrained();

            // Master switch: nothing runs for a unit until its admin has
            // configured and enabled it, so a go-live can never surprise a
            // campus with auto-alpa notifications it never asked for.
            $table->boolean('enabled')->default(false);

            // ISO day-of-week numbers, 1 = Monday ... 7 = Sunday.
            $table->json('days');

            $table->time('masuk_opens_at');
            $table->time('masuk_closes_at');
            $table->time('masuk_late_after')->nullable();

            $table->boolean('pulang_enabled')->default(false);
            $table->time('pulang_opens_at')->nullable();
            $table->time('pulang_closes_at')->nullable();

            // 'wali_kelas' = homeroom teacher marks the roster in class
            // (PG/RA/TK/SD); 'gerbang' = students self check-in at the gate
            // with rotating QR + radius + device-once (SMP/SMA).
            $table->enum('intake_mode', ['wali_kelas', 'gerbang']);

            $table->boolean('geo_required')->default(false);
            $table->decimal('gate_lat', 10, 7)->nullable();
            $table->decimal('gate_lng', 10, 7)->nullable();
            $table->unsignedInteger('geo_radius_m')->nullable();

            $table->boolean('qr_required')->default(true);
            $table->string('public_slug', 32)->nullable()->unique();

            // Volume valves: a unit can silence the chatty per-event messages
            // and keep only the absent alert (or any combination).
            $table->boolean('notify_masuk')->default(true);
            $table->boolean('notify_pulang')->default(true);
            $table->boolean('notify_absent')->default(true);

            $table->timestamps();
        });

        Schema::create('daily_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('school_unit_id')->constrained();
            $table->date('date');
            $table->enum('type', ['masuk', 'pulang']);

            // Snapshots of the settings at open time, so changing tomorrow's
            // bell never rewrites the history of a session already run.
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->time('late_after')->nullable();

            $table->enum('status', ['open', 'closed'])->default('open');

            // Null = opened by the scheduler, which is the normal case; a
            // human id is only recorded when someone reopens manually.
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // One session per unit per day per type - the idempotency anchor
            // the scheduler relies on (R5: a twice-fired cron creates nothing
            // the second time, the constraint catches it, not the code).
            $table->unique(['school_unit_id', 'date', 'type']);
            $table->index(['date', 'status']);
        });

        Schema::create('daily_records', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('daily_session_id')->constrained('daily_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // Denormalized from the student's current enrollment for the same
            // reason attendance_records carries it - reports group by
            // classroom without joining live enrollments. Nullable on
            // purpose: a student not yet placed in any classroom (T22's
            // "belum ber-rombel") still gets gate attendance.
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();

            // A term is never deleted while it still has ledger entries -
            // restrict, same reasoning as attendance_records.term_id.
            $table->foreignId('term_id')->constrained()->restrictOnDelete();

            $table->date('date');

            $table->enum('attendance_status', ['hadir', 'sakit', 'izin', 'alpa']);

            // 'Terlambat' is a flavour of hadir, not a fifth status: rollups
            // keep counting H/S/I/A and late-ness is reported alongside.
            $table->boolean('is_late')->default(false);

            $table->enum('source', ['self', 'wali_kelas', 'tu']);

            // The wall-clock moment of a self check-in; manual marks carry
            // recorded_by instead.
            $table->timestamp('checked_in_at')->nullable();

            // Anti "one phone checks in the whole gang": a hash of the
            // browser's device id + fingerprint, never raw identifiers. The
            // partial unique index below is what enforces one-per-session.
            $table->string('device_hash', 64)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();

            $table->enum('record_status', ['recorded', 'revoked'])->default('recorded');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'term_id', 'record_status']);
            $table->index('date');
        });

        // Partial unique indexes in raw SQL - Laravel's unique()->where() is
        // silently ignored by both the SQLite and Postgres grammars (the T5
        // lesson), and these two constraints ARE the anti-fraud story:
        //
        // 1. one live mark per student per session - "absen dua kali" is
        //    impossible no matter which path wrote it;
        // 2. one live mark per DEVICE per session - a single phone cannot
        //    check in a second student's NIS, closing the buddy-punching lane
        //    that GPS radius alone cannot (the friend is legitimately at the
        //    gate).
        DB::statement(
            'CREATE UNIQUE INDEX daily_records_one_live_mark_per_student'
            .' ON daily_records (daily_session_id, student_id)'
            .' WHERE record_status = \'recorded\''
        );
        DB::statement(
            'CREATE UNIQUE INDEX daily_records_one_live_mark_per_device'
            .' ON daily_records (daily_session_id, device_hash)'
            .' WHERE record_status = \'recorded\' AND device_hash IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_records');
        Schema::dropIfExists('daily_sessions');
        Schema::dropIfExists('daily_settings');
    }
};
