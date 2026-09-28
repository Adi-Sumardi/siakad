<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The idempotent sweep that finishes what the per-column encryption
     * migrations left behind wherever they had already "run": their
     * backfills used DB::table()->whereKey(), which the query builder does
     * not have - its __call rewrote the unknown method into WHERE key = ?,
     * a column that does not exist, and the update silently matched
     * nothing (found 2026-09-28: 49/49 login_otps and 62/63
     * notification_logs rows were still plaintext after "successful"
     * migrations; the decrypt-tolerant read path masked it completely).
     *
     * Safe to run on ANY database state: each row is only rewritten when
     * its current value does not already decrypt into the format its model
     * reads. Two formats are in play and must not be mixed per column:
     *  - FieldEncrypter (serialize-based Crypt::encrypt) for the
     *    HasEncryptedAttributes trait columns,
     *  - Crypt::encryptString (no serialize) for the native encrypted:array
     *    cast on integration_events.payload.
     */
    public function up(): void
    {
        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        // Try to read a value as its plaintext; null = not decryptable at
        // all (i.e. still plaintext, or empty).
        $viaSerialized = function (string $value): ?string {
            try {
                $plain = Crypt::decrypt($value);

                return is_string($plain) ? $plain : null;
            } catch (DecryptException) {
                return null;
            }
        };
        $viaString = function (string $value): ?string {
            try {
                return Crypt::decrypt($value, false);
            } catch (DecryptException) {
                return null;
            }
        };

        // Trait-idiom columns (serialized format is the target).
        foreach ([
            ['login_otps', 'identifier'],
            ['notification_logs', 'recipient'],
            ['account_invitations', 'sent_to'],
        ] as [$table, $column]) {
            DB::table($table)->orderBy('id')->each(function ($row) use ($encrypter, $viaSerialized, $viaString, $table, $column) {
                $value = $row->{$column};
                if ($value === null || $value === '') {
                    return;
                }

                if ($viaSerialized($value) !== null) {
                    return; // already in the trait's format
                }

                // Either the no-serialize format (convert) or still
                // plaintext (encrypt as-is).
                $plain = $viaString($value) ?? $value;
                $updates = [$column => $encrypter->encrypt($plain)];

                if ($table === 'login_otps' && $row->identifier_hash === null) {
                    $updates['identifier_hash'] = $encrypter->blindIndex($plain);
                }

                DB::table($table)->where('id', $row->id)->update($updates);
            });
        }

        // Native encrypted:array column (string format over JSON is the
        // target).
        DB::table('integration_events')->orderBy('id')->each(function ($row) use ($viaString, $viaSerialized) {
            $value = $row->payload;
            if ($value === null || $value === '') {
                return;
            }

            if ($viaString($value) !== null) {
                return; // already in the native format
            }

            $json = $viaSerialized($value) ?? $value;
            DB::table('integration_events')->where('id', $row->id)->update([
                'payload' => Crypt::encryptString($json),
            ]);
        });
    }

    public function down(): void
    {
        // One-way by design.
    }
};
