<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The spec's staff contact column (03-ERD: staff_profiles carries
         * "data guru/staf yang tidak layak ditaruh di users", phone enc).
         * Mirrored from users.phone by StaffProfile::mirrorUserPhone() - one
         * field in the UI, two homes: the login identifier stays on
         * users.phone (OTP hashes against users.phone_hash), the staff
         * record's copy lives here.
         *
         * Hash is a plain index, not unique, unlike users.phone_hash: this
         * is a lookup convenience, not a login identifier, and the mirrored
         * number legitimately equals the users.phone value.
         */
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->text('phone')->nullable();
            $table->string('phone_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropIndex(['phone_hash']);
            $table->dropColumn(['phone', 'phone_hash']);
        });
    }
};
