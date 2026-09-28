<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T51-b: the login identifier (email/phone a code was sent to) is
        // PII. Widen for ciphertext, add the deterministic blind index the
        // verify() lookup moves to, then re-encrypt existing rows in place.
        Schema::table('login_otps', function (Blueprint $table) {
            $table->text('identifier')->change();
            $table->string('identifier_hash', 64)->nullable()->after('identifier');
        });

        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        // Backfill raw, same constraint as the notification_logs recipient:
        // the model now decrypts on read, so still-plaintext rows cannot
        // pass back through Eloquent. NOTE: where('id', ...), never
        // whereKey() - the query builder has no whereKey, and its __call
        // silently rewrites the unknown method into WHERE key = ?, a column
        // that does not exist (the trap that no-op'd the original
        // notification_logs backfill).
        DB::table('login_otps')->orderBy('id')->each(function ($row) use ($encrypter) {
            DB::table('login_otps')->where('id', $row->id)->update([
                'identifier' => $encrypter->encrypt($row->identifier),
                'identifier_hash' => $encrypter->blindIndex($row->identifier),
            ]);
        });

        Schema::table('login_otps', function (Blueprint $table) {
            // The old composite index is dead weight over ciphertext - the
            // lookup key is the blind index now.
            $table->dropIndex(['identifier', 'consumed_at']);
            $table->index('identifier_hash');
        });
    }

    public function down(): void
    {
        // One-way by design: the plaintext these columns carried is exactly
        // what this migration exists to remove.
    }
};
