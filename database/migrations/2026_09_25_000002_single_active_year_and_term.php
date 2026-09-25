<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Exactly one active academic year and one active term - at the
        // DATABASE level (audit T47, the invariant activate() only enforced
        // in code): two admin tabs racing activate() (or a hand-edited row)
        // left two active years, and every "current" query then picked
        // arbitrarily - the SPP generator, dashboards and grade writes all
        // silently disagreeing about which year it is. Partial unique
        // indexes, raw SQL per the T5 precedent ('unique()->where()' is
        // silently ignored by Laravel's grammar); the boolean literal TRUE
        // reads correctly on both SQLite and Postgres. Fails hard on deploy
        // if duplicate actives already exist - by design, the same choice
        // the T5 extracurricular index made.
        DB::statement(
            'CREATE UNIQUE INDEX academic_years_single_active '
            .'ON academic_years (is_active) '
            .'WHERE is_active = TRUE'
        );

        // One active TERM globally (not per year): Term::current() is the
        // single definition every write lane reads, so two lit terms would
        // split the app between two semesters even within one year.
        DB::statement(
            'CREATE UNIQUE INDEX terms_single_active '
            .'ON terms (is_active) '
            .'WHERE is_active = TRUE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS terms_single_active');
        DB::statement('DROP INDEX IF EXISTS academic_years_single_active');
    }
};
