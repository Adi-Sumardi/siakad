<?php

namespace App\Services\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encryption for individual PII columns, plus the deterministic blind index
 * that makes those columns searchable and constrainable.
 *
 * Adapted from PMB's DataEncryptionService with one deliberate change: failures
 * are not swallowed. PMB caught encryption exceptions and returned the original
 * value, which means a broken APP_KEY silently writes plaintext NIK into a
 * column everyone believes is encrypted. Failing loudly is the safer direction.
 */
class FieldEncrypter
{
    public function encrypt(string $value): string
    {
        return Crypt::encrypt($value);
    }

    /**
     * Decrypts a stored value, tolerating values that were never encrypted.
     *
     * The tolerance is for seeds and imported fixtures, not for production
     * rows: a genuinely corrupt ciphertext returns as-is rather than taking
     * down a whole student list over one bad row.
     */
    public function decrypt(string $value): string
    {
        try {
            return Crypt::decrypt($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    /**
     * Deterministic hash for a companion `*_hash` column.
     *
     * Crypt::encrypt() uses a random IV, so the same NIK encrypts differently
     * every save and a unique index on the ciphertext never fires - that is
     * exactly how PMB ended up able to store two students with one NIK. HMAC
     * keyed with APP_KEY is stable for the same input, safe to index, and does
     * not reveal the plaintext.
     */
    public function blindIndex(string $value): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($value)), (string) config('app.key'));
    }
}
