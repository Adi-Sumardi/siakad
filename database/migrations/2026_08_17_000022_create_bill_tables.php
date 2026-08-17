<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * A bulk issue. Exists to answer "why did 300 bills appear last night",
         * which is otherwise unanswerable once the bills are indistinguishable
         * from ones typed by hand.
         */
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('fee_type_id')->constrained('fee_types')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->nullOnDelete();

            $table->smallInteger('period_month')->nullable();   // 1-12, monthly fees only

            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->integer('bills_created')->default(0);
            $table->integer('bills_skipped')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);

            // What the preview showed, kept so the run can be compared against
            // what an admin was told would happen before they pressed go.
            $table->json('skipped_detail')->nullable();

            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('bill_number')->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();

            // The audit trail: which catalogue row and which run produced this.
            $table->foreignId('fee_type_id')->constrained('fee_types')->restrictOnDelete();
            $table->foreignId('fee_rate_id')->nullable()->constrained('fee_rates')->nullOnDelete();
            $table->foreignId('billing_run_id')->nullable()->constrained('billing_runs')->nullOnDelete();

            // 'spp:2026-2027:07'. With the unique index below, this is what makes
            // the monthly generator safe to run twice - the pattern PMB settled
            // on for the same reason.
            $table->string('dedup_key');

            $table->smallInteger('period_month')->nullable();
            $table->string('description');

            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('late_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);

            // Maintained only by PaymentAllocator, recomputed from the
            // allocation rows rather than incremented - incremental cache
            // columns are exactly how PMB's four status fields drifted apart.
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);

            $table->enum('status', ['draft', 'unpaid', 'partial', 'paid', 'overdue', 'cancelled', 'waived'])
                ->default('unpaid');

            $table->date('due_date');
            $table->date('grace_period_end')->nullable();
            $table->boolean('allow_installment')->default(false);


            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'dedup_key']);
            $table->index(['student_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index(['academic_year_id', 'fee_type_id']);
        });

        /**
         * The itemisation. SPP has one line; seragam has four and a parent will
         * ask about each. Discounts are lines too, with a negative amount, so
         * the printed rows always sum to total_amount.
         */
        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('fee_component_id')->nullable()->constrained('fee_components')->nullOnDelete();

            $table->string('name');
            $table->smallInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->string('size_option')->nullable();
            $table->text('notes')->nullable();

            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        /** For seragam and uang buku, which are paid in two or three goes. */
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->smallInteger('sequence');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->enum('status', ['unpaid', 'paid', 'overdue'])->default('unpaid');
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['bill_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedules');
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('billing_runs');
    }
};
