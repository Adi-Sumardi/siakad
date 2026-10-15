<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point records stop counting the moment a teacher writes them. A violation
 * now climbs wali kelas -> waka kesiswaan (-> kepala sekolah when the rule is
 * 'berat'); a merit needs the wali kelas only. Until the last stage approves
 * the row is 'pending' and no balance, rapor or parent screen sees it - every
 * balance in the app already sums status = 'recorded' only.
 *
 * Waka kesiswaan and kepala sekolah become their own unit-scoped roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'admin_unit', 'guru', 'orangtua', 'waka_kesiswaan', 'kepala_sekolah'])
                ->default('orangtua')->change();
        });

        Schema::table('point_rules', function (Blueprint $table) {
            // Decides how far a violation climbs: 'berat' adds the kepala
            // sekolah stage. Meaningless for merits, which stop at wali kelas.
            $table->enum('severity', ['ringan', 'sedang', 'berat'])->default('ringan')->after('category');
        });

        Schema::table('point_records', function (Blueprint $table) {
            $table->enum('status', ['pending', 'recorded', 'rejected', 'revoked'])->default('recorded')->change();
            // Copied from the rule at write time, like points: editing the
            // catalogue later must not reroute a record already in flight.
            $table->enum('severity', ['ringan', 'sedang', 'berat'])->nullable()->after('points');
            // Who has to act next while status = pending; null otherwise.
            $table->enum('approval_stage', ['wali_kelas', 'waka_kesiswaan', 'kepala_sekolah'])->nullable()->after('status');
            $table->index(['status', 'approval_stage']);
        });

        $this->restorePartialMeritIndex();

        Schema::create('point_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_record_id')->constrained('point_records')->cascadeOnDelete();
            $table->enum('stage', ['wali_kelas', 'waka_kesiswaan', 'kepala_sekolah']);
            // 'skipped' = the stage needed nobody (the recorder holds that
            // seat, or the class has no wali kelas) - still written down so
            // the trail always shows every stage.
            $table->enum('decision', ['approved', 'rejected', 'skipped']);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['point_record_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_approvals');

        Schema::table('point_records', function (Blueprint $table) {
            $table->dropIndex(['status', 'approval_stage']);
            $table->dropColumn(['severity', 'approval_stage']);
            $table->enum('status', ['recorded', 'revoked'])->default('recorded')->change();
        });

        $this->restorePartialMeritIndex();

        Schema::table('point_rules', function (Blueprint $table) {
            $table->dropColumn('severity');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'admin_unit', 'guru', 'orangtua'])->default('orangtua')->change();
        });
    }

    /**
     * SQLite applies ->change() by rebuilding the table, and the rebuild
     * re-creates point_records_one_active_merit_per_achievement WITHOUT its
     * WHERE clause - a full unique on related_achievement_id, which breaks
     * re-awarding after a revoke. Put the partial index back as it was
     * (2026_09_28_000001); on Postgres this is a harmless drop + re-create.
     */
    private function restorePartialMeritIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS point_records_one_active_merit_per_achievement');
        DB::statement(
            'CREATE UNIQUE INDEX point_records_one_active_merit_per_achievement '
            .'ON point_records (related_achievement_id) '
            ."WHERE status = 'recorded' AND related_achievement_id IS NOT NULL"
        );
    }
};
