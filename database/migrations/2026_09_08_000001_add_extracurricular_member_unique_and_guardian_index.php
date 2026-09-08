<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One active membership per (extracurricular, student, year) - partial,
         * because only the *active* duplicate is a mistake. A student who
         * leaves and rejoins the same year keeps both rows (status='left'
         * history), the same way enrollment history is worth keeping. Without
         * this, a double-assign produces two active rows, the roster counts
         * the student twice, and an ekskul billed via requires_roster_membership
         * can be charged twice. Same reasoning as bills.dedup_key: "already a
         * member" is a fact worth a constraint, not a check someone remembers
         * to run. Fails loudly on any pre-existing duplicate - by design.
         *
         * Stated raw because Laravel's unique() cannot express a WHERE clause
         * (a ->where() on the fluent definition is silently ignored), and a
         * plain unique would wrongly block rejoining after leaving in the
         * same year. Partial-index syntax is identical on SQLite and Postgres.
         */
        DB::statement(
            'CREATE UNIQUE INDEX extracurricular_members_active_unique '
            .'ON extracurricular_members (extracurricular_id, student_id, academic_year_id) '
            ."WHERE status = 'active'"
        );

        /**
         * "All children of this guardian" runs on every wali page load (D3 -
         * one login, many children). The existing unique(student_id,
         * guardian_id) only serves the other direction, "who are this
         * student's guardians", and cannot serve a guardian_id-only filter.
         */
        Schema::table('student_guardians', function (Blueprint $table) {
            $table->index('guardian_id', 'student_guardians_guardian_id_index');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS extracurricular_members_active_unique');

        Schema::table('student_guardians', function (Blueprint $table) {
            $table->dropIndex('student_guardians_guardian_id_index');
        });
    }
};
