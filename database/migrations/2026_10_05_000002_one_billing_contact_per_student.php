<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Exactly one billing contact per student - at the DATABASE level
        // (audit 2026-10-05). The handoff lane tracked "assigned" per event
        // only, so a redelivered or re-enrolled PMB event whose primary
        // guardian differed left two is_billing_contact=true pivots; every
        // reader picks firstWhere(is_billing_contact), so reminders,
        // receipts and refunds could reach the wrong parent. Partial unique
        // index per the T5/T47 precedent ('unique()->where()' is silently
        // ignored by Laravel's grammar); the boolean literal reads on both
        // SQLite and Postgres.
        //
        // Unlike the single-active-year index this one DEMOTES duplicates
        // first instead of failing the deploy: the handoff bug makes
        // duplicates likely in production, and the demotion is exactly the
        // repair an admin would do by hand - keep the first-attached
        // contact (lowest pivot id, which is also what firstWhere() was
        // already picking in practice), demote the rest.
        DB::statement(
            'UPDATE student_guardians SET is_billing_contact = FALSE '
            .'WHERE is_billing_contact = TRUE AND id NOT IN ('
            .'  SELECT MIN(id) FROM student_guardians WHERE is_billing_contact = TRUE GROUP BY student_id'
            .')'
        );

        DB::statement(
            'CREATE UNIQUE INDEX student_guardians_one_billing_contact '
            .'ON student_guardians (student_id) '
            .'WHERE is_billing_contact = TRUE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS student_guardians_one_billing_contact');
    }
};
