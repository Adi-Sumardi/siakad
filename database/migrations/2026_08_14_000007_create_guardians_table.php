<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            // Null for a parent with no login of their own - typically the
            // mother when the father holds the account, or the reverse. They
            // still need a record: they are the contact on file.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            $table->string('nama');
            $table->enum('hubungan', ['ayah', 'ibu', 'wali']);

            $table->text('no_hp')->nullable();
            $table->string('no_hp_hash', 64)->nullable()->index();
            $table->string('email')->nullable();

            $table->string('pekerjaan')->nullable();
            $table->string('penghasilan_bulanan')->nullable();
            $table->text('alamat')->nullable();

            $table->timestamps();
        });

        /**
         * The table that makes "one login, several children" work, and the
         * clearest break from PMB, where one account meant one registrant.
         * A guardian with three children across three units signs in once and
         * settles all of their bills in a single transaction.
         */
        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();

            $table->enum('relationship', ['ayah', 'ibu', 'wali']);
            $table->boolean('is_primary')->default(false);

            // Where invoices and payment reminders go. At most one per student -
            // enforced in the service that writes it, since "at most one true
            // per group" is not expressible as a unique index.
            $table->boolean('is_billing_contact')->default(false);

            $table->timestamps();

            $table->unique(['student_id', 'guardian_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('guardians');
    }
};
