<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The schema-level half of achievement-verification idempotency (bug
     * batch Poin 6B): at most ONE active merit row per achievement in
     * point_records. The API already refuses re-verification with 409 and
     * the decision service awards points only inside the pending ->
     * verified transition, but those are code paths - this index is what
     * makes a double award impossible even for a future writer that
     * forgets the rules.
     *
     * PARTIAL (status = 'recorded') on purpose: a revoke is the app's
     * sanctioned correction (PointLedger docblock), so revoked duplicates
     * left over from the pre-fix bug must not block the index - and a
     * repair that revokes a duplicate must not violate it either.
     *
     * If ACTIVE duplicates exist, this migration REFUSES to run rather
     * than touching data: audit first (prestasi:audit-points), review the
     * output with the user, repair (prestasi:repair-points --confirm),
     * then migrate again.
     */
    public function up(): void
    {
        $duplicates = DB::table('point_records')
            ->where('status', 'recorded')
            ->whereNotNull('related_achievement_id')
            ->select('related_achievement_id', DB::raw('COUNT(*) as total'))
            ->groupBy('related_achievement_id')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'point_records masih menyimpan '.$duplicates->count().' prestasi dengan lebih dari satu baris merit AKTIF - '
                .'membuat unique index sekarang akan gagal. Jalankan `php artisan prestasi:audit-points`, '
                .'review hasilnya, lalu `php artisan prestasi:repair-points --confirm`, baru migrasikan ulang.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX point_records_one_active_merit_per_achievement '
            ."ON point_records (related_achievement_id) "
            ."WHERE status = 'recorded' AND related_achievement_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS point_records_one_active_merit_per_achievement');
    }
};
