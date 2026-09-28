<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T51-b: an invitation's target email/phone is PII. Widen for
        // ciphertext, then re-encrypt existing rows in place (raw - the
        // model's encrypted cast would try to decrypt on read).
        Schema::table('account_invitations', function (Blueprint $table) {
            $table->text('sent_to')->change();
        });

        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        DB::table('account_invitations')
            ->whereNotNull('sent_to')
            ->orderBy('id')
            ->each(function ($row) use ($encrypter) {
                DB::table('account_invitations')
                    ->where('id', $row->id)
                    ->update(['sent_to' => $encrypter->encrypt($row->sent_to)]);
            });
    }

    public function down(): void
    {
        // One-way by design.
    }
};
