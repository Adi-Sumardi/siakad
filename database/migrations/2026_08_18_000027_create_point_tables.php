<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The catalogue of things that earn or cost points - "Terlambat masuk
         * kelas", "Juara lomba tahfidz". `points` is stored positive here; the
         * sign only appears on the ledger row, where `type` decides it.
         */
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            // Null means school-wide (e.g. "terlambat" applies everywhere);
            // filled means one unit wrote its own rule (e.g. a hafalan target
            // that only applies to the units that run one).
            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->enum('type', ['violation', 'merit']);
            $table->string('category');
            $table->smallInteger('points');
            $table->boolean('requires_evidence')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['school_unit_id', 'code']);
        });

        /**
         * Where a running balance sits, and what that means - "-25..-49 ->
         * Peringatan 1 -> surat pemberitahuan wali". Read by the UI to colour a
         * badge, and by the scheduled evaluator to decide who gets notified.
         */
        Schema::create('point_thresholds', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->cascadeOnDelete();

            $table->integer('min_points');
            $table->integer('max_points');
            $table->string('label');
            $table->text('action')->nullable();
            $table->string('color')->nullable();
            $table->boolean('notify_guardian')->default(true);

            $table->timestamps();

            $table->index(['school_unit_id', 'min_points', 'max_points']);
        });

        /**
         * The ledger. One row per event, signed, never edited once written - a
         * correction is a revoke with a reason, not an UPDATE or a DELETE. See
         * docs/01-ARSITEKTUR.md D6: a balance column would answer "what is it
         * now"; this answers "who says so, and why", which is the question that
         * actually gets asked when a parent disputes it.
         */
        Schema::create('point_records', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            // A term is never deleted while it still has ledger entries -
            // restrict, not cascade, so clearing old data cannot take a
            // family's dispute evidence with it.
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();

            // Nullable so a one-off event with no catalogue entry can still be
            // recorded - the ledger must not refuse a real event just because
            // nobody added it to the catalogue first.
            $table->foreignId('point_rule_id')->nullable()->constrained('point_rules')->nullOnDelete();

            // Set when the points came from verifying an achievement, so a
            // parent looking at the ledger sees which prestasi it was for
            // instead of a bare "+50 poin".
            $table->foreignId('related_achievement_id')->nullable()->constrained('achievements')->nullOnDelete();

            $table->enum('type', ['violation', 'merit']);
            // Signed: -10 for a violation, +15 for a merit. Copied from the rule
            // at write time so a later edit to the catalogue cannot rewrite
            // history.
            $table->smallInteger('points');

            $table->date('occurred_on');
            $table->text('description');
            $table->string('evidence_path')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', ['recorded', 'revoked'])->default('recorded');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            // Set when a guardian opens the notification about this record -
            // distinct from the threshold-crossing email, which is about the
            // running balance, not this one event.
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'term_id', 'status']);
            $table->index('occurred_on');
        });

        /**
         * One row per threshold a student has already been notified about, for
         * a given term. The unique index is what stops the daily evaluator from
         * emailing a family every day their child stays in "Peringatan 1" -
         * without it, a threshold crossing would nag exactly like an
         * un-deduplicated bill reminder would.
         */
        Schema::create('point_threshold_notifications', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->foreignId('point_threshold_id')->constrained('point_thresholds')->cascadeOnDelete();

            $table->integer('balance_at_notification');
            $table->timestamp('notified_at');

            $table->timestamps();

            $table->unique(['student_id', 'term_id', 'point_threshold_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_threshold_notifications');
        Schema::dropIfExists('point_records');
        Schema::dropIfExists('point_thresholds');
        Schema::dropIfExists('point_rules');
    }
};
