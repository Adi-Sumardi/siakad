<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Aligns point_threshold_notifications.term_id with point_records:
         * restrict, not cascade. The notification row is the answer to "kapan
         * wali murid ini diberi tahu" - the same audit-trail stance that made
         * point_records restrict a term with ledger entries. Cascading it away
         * meant a term could be deleted with the notification history gone but
         * the ledger blocking the delete anyway - two answers to one question.
         *
         * Nothing in the app deletes terms today; this is consistency, not a
         * bugfix for a live path.
         *
         * PostgreSQL only: SQLite (tests, dev) cannot alter an FK in place,
         * would need a full table rebuild, and no test exercises term
         * deletion - the fresh-database create migration keeps cascade there.
         */
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE point_threshold_notifications DROP CONSTRAINT IF EXISTS point_threshold_notifications_term_id_foreign');
        DB::statement('ALTER TABLE point_threshold_notifications ADD CONSTRAINT point_threshold_notifications_term_id_foreign FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE point_threshold_notifications DROP CONSTRAINT IF EXISTS point_threshold_notifications_term_id_foreign');
        DB::statement('ALTER TABLE point_threshold_notifications ADD CONSTRAINT point_threshold_notifications_term_id_foreign FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE');
    }
};
