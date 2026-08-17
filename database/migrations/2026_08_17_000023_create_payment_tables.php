<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One row per transaction, whichever way the money arrived: a Xendit
         * invoice, a transfer receipt an admin verified, or cash at the front
         * desk. One ledger, so no figure has to be reconciled against a second
         * table that might disagree.
         */
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('payment_number')->unique();

            $table->foreignId('payer_guardian_id')->nullable()->constrained('guardians')->nullOnDelete();

            // The amount actually transacted. It can cover several bills across
            // several children - see payment_allocations.
            $table->decimal('amount', 12, 2);

            $table->enum('method', [
                'virtual_account', 'e_wallet', 'qris', 'bank_transfer', 'credit_card', 'cash', 'other',
            ])->nullable();
            $table->string('channel')->nullable();   // BCA, BRI, OVO, ...

            $table->enum('status', [
                'pending', 'processing', 'completed', 'failed', 'expired', 'cancelled', 'refunded',
            ])->default('pending');

            // Unique from day one. PMB shipped this column without the
            // constraint and a redelivered Xendit callback could double-count a
            // payment; nullable-unique behaves on Postgres and SQLite alike.
            $table->string('external_transaction_id')->nullable()->unique();
            $table->string('invoice_id')->nullable();
            $table->string('invoice_url')->nullable();

            $table->json('gateway_response')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Manual transfer evidence.
            $table->string('receipt_file_path')->nullable();
            $table->string('receipt_file_name')->nullable();
            $table->integer('receipt_file_size')->nullable();
            $table->string('receipt_file_mime')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['payer_guardian_id', 'created_at']);
        });

        /**
         * Which bills a payment settled, and by how much.
         *
         * This table is why a parent can clear three months of SPP for two
         * children in one Xendit invoice and one bank admin fee, while each
         * bill still knows exactly what it received. A bill's paid_amount is
         * recomputed as SUM(amount) over these rows - never incremented.
         */
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamps();

            $table->unique(['payment_id', 'bill_id']);
            $table->index('bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
