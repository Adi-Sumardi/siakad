<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** What the school charges for. The catalogue, not the amounts. */
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('code')->unique();   // spp, seragam, buku, kegiatan, ...
            $table->string('name');

            $table->enum('recurrence', ['monthly', 'per_term', 'once']);
            $table->boolean('allow_installment')->default(false);

            // Seragam needs sizes chosen before the bill is final; SPP does not.
            $table->boolean('requires_selection')->default(false);

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });

        /**
         * The amount, per unit, per level, per year.
         *
         * Bills keep a reference to the row they were priced from. PMB's v2
         * audit found the opposite - settings tables that no bill pointed at,
         * so nobody could answer which rate produced a given charge.
         */
        Schema::create('fee_rates', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('fee_type_id')->constrained('fee_types')->cascadeOnDelete();
            $table->foreignId('school_unit_id')->constrained('school_units')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            // Null means every level in that unit - most fees are per unit, not
            // per class, and forcing a row per level would multiply the table
            // for no gain.
            $table->smallInteger('tingkat')->nullable();

            $table->decimal('amount', 12, 2);

            // Which day of the month SPP falls due. Only meaningful for monthly
            // fees; the generator reads it, everything else ignores it.
            $table->smallInteger('due_day')->nullable();

            $table->decimal('late_fee_amount', 12, 2)->default(0);
            $table->smallInteger('late_fee_grace_days')->default(0);

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // One live rate per (type, unit, level, year). A duplicate here is
            // how a cohort quietly ends up billed two different amounts.
            $table->unique(['fee_type_id', 'school_unit_id', 'tingkat', 'academic_year_id'], 'fee_rates_scope_unique');
        });

        /**
         * The line items inside a packaged fee - "Kemeja putih 2 pcs", "Celana
         * panjang". Expanded into bill_lines when a bill is issued, so a parent
         * asking what the Rp 875.000 covers has an answer.
         */
        Schema::create('fee_components', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('fee_rate_id')->constrained('fee_rates')->cascadeOnDelete();

            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->smallInteger('default_qty')->default(1);
            $table->boolean('is_optional')->default(false);
            $table->boolean('has_size_option')->default(false);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_components');
        Schema::dropIfExists('fee_rates');
        Schema::dropIfExists('fee_types');
    }
};
