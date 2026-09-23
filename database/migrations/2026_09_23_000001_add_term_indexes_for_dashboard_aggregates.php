<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard aggregates (and the attention drill-down) filter
 * grades/point_records by term first. Neither table's existing indexes lead
 * with term_id, so those grouped queries scanned the whole table - fine in
 * dev, a per-refresh cost at two thousand students.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->index('term_id');
        });

        Schema::table('point_records', function (Blueprint $table) {
            $table->index('term_id');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex(['term_id']);
        });

        Schema::table('point_records', function (Blueprint $table) {
            $table->dropIndex(['term_id']);
        });
    }
};
