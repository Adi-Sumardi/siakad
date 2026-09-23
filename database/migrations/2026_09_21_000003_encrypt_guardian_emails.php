<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guardian.email joins no_hp behind encryption (audit 2026-09-21: the HP was
 * in a safe while the email sat on the table). Adds the email_hash blind
 * index and encrypts the existing plaintext in place.
 *
 * The data pass reads RAW rows on purpose: once the model lists email in
 * $encrypted, reading through Eloquent would try to decrypt what is still
 * plaintext. Rows are re-saved through the encrypter directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            // Ciphertext is far longer than the address it hides (a 26-char
            // email encrypts to 256 chars), so the original varchar(255)
            // would reject any ordinary address on Postgres - SQLite never
            // enforces the length, which is why the suite didn't catch it.
            // Same type no_hp already uses.
            $table->text('email')->nullable()->change();
            $table->string('email_hash', 64)->nullable()->after('email');
            $table->index('email_hash');
        });

        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        DB::table('guardians')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id') // each() chunks; a chunk needs a stable order
            ->select(['id', 'email'])
            ->each(function ($row) use ($encrypter) {
                DB::table('guardians')
                    ->where('id', $row->id)
                    ->update([
                        'email' => $encrypter->encrypt($row->email),
                        'email_hash' => $encrypter->blindIndex($row->email),
                    ]);
            });
    }

    public function down(): void
    {
        // Decrypting back requires reading through the model's casts; a raw
        // reversal would need the encrypter anyway, so do exactly that.
        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        DB::table('guardians')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id') // each() chunks; a chunk needs a stable order
            ->select(['id', 'email'])
            ->each(function ($row) use ($encrypter) {
                try {
                    $plain = $encrypter->decrypt($row->email);
                } catch (\Throwable) {
                    return; // already plaintext - leave it be
                }

                DB::table('guardians')->where('id', $row->id)->update(['email' => $plain]);
            });

        Schema::table('guardians', function (Blueprint $table) {
            $table->dropIndex(['email_hash']);
            $table->dropColumn('email_hash');
        });
    }
};
