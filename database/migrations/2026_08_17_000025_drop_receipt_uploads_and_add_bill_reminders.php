<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Receipt uploads go. Every online payment settles through the Xendit
         * callback, and cash at the front desk is recorded by the admin who
         * took it - neither produces a transfer slip for anyone to photograph.
         * The columns had no writer, and columns with no writer grow a half-
         * built feature around them eventually.
         */
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'receipt_file_path',
                'receipt_file_name',
                'receipt_file_size',
                'receipt_file_mime',
            ]);
        });

        /**
         * One row per reminder actually sent.
         *
         * The unique index is the whole point: the reminder job runs daily and
         * must not message a family twice about the same bill on the same beat.
         * Same reasoning as bills.dedup_key - "sent already" is a fact worth a
         * constraint, not a flag someone remembers to check.
         */
        Schema::create('bill_reminders', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();

            // Which beat this was: a week before, the day before, three days late.
            $table->enum('kind', ['h7', 'h1', 'overdue']);
            $table->enum('channel', ['email', 'whatsapp']);
            $table->string('sent_to');
            $table->timestamp('sent_at');

            $table->timestamps();

            $table->unique(['bill_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_reminders');

        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_file_path')->nullable();
            $table->string('receipt_file_name')->nullable();
            $table->integer('receipt_file_size')->nullable();
            $table->string('receipt_file_mime')->nullable();
        });
    }
};
