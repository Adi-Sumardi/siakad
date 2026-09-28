<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The schema backstop for the duplicate-proposal guard (audit
     * 2026-09-28): the exists() check and the insert are two statements, so
     * a genuine double-click (two concurrent POSTs - the exact threat the
     * guard names) could pass the check on both requests and stack two
     * pending cards that each award their own points at verify time.
     *
     * Partial on purpose: only DATED student proposals (the shape a
     * double-click produces - both requests carry the same form). Undated
     * and teacher-self proposals stay guarded by the application check
     * alone; they cannot be covered here without a nullable-key collation
     * trick that SQLite and PostgreSQL do not share.
     */
    public function up(): void
    {
        $duplicates = DB::table('achievements')
            ->where('status', 'pending')
            ->whereNotNull('student_id')
            ->whereNotNull('tanggal_event')
            ->selectRaw('student_id, nama_prestasi, tanggal_event, COUNT(*) as total')
            ->groupBy('student_id', 'nama_prestasi', 'tanggal_event')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Masih ada '.$duplicates->count().' kombinasi pengajuan prestasi pending yang identik - '
                .'unique index tidak bisa dibuat. Selesaikan duplikatnya (satu tinggal diverifikasi, sisanya ditolak) lalu migrasikan ulang.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX achievements_one_pending_proposal_per_win '
            ."ON achievements (student_id, nama_prestasi, tanggal_event) "
            ."WHERE status = 'pending' AND student_id IS NOT NULL AND tanggal_event IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS achievements_one_pending_proposal_per_win');
    }
};
