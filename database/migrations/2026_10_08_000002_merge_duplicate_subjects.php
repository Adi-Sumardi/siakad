<?php

use App\Services\Academic\SubjectMerger;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Folds IND7/IND8/IND9-style duplicates into one subject per unit and name. See SubjectMerger. */
    public function up(): void
    {
        app(SubjectMerger::class)->apply();
    }

    public function down(): void
    {
        app(SubjectMerger::class)->rollback();
    }
};
