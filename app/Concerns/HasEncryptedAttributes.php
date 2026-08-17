<?php

namespace App\Concerns;

use App\Services\Security\FieldEncrypter;
use InvalidArgumentException;

/**
 * Encrypts the attributes a model lists in `$encrypted`, and keeps the blind
 * index columns declared in `$encryptedHashes` in step with them.
 *
 * Only fields that need a uniqueness constraint or a lookup get a hash; a free
 * text address is encrypted without one.
 */
trait HasEncryptedAttributes
{
    protected function encrypter(): FieldEncrypter
    {
        return app(FieldEncrypter::class);
    }

    /** @return list<string> */
    protected function encryptedAttributes(): array
    {
        return property_exists($this, 'encrypted') ? $this->encrypted : [];
    }

    /** @return array<string, string> */
    protected function encryptedHashColumns(): array
    {
        return property_exists($this, 'encryptedHashes') ? $this->encryptedHashes : [];
    }

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($value !== null && $value !== '' && in_array($key, $this->encryptedAttributes(), true)) {
            return $this->encrypter()->decrypt($value);
        }

        return $value;
    }

    public function setAttribute($key, $value)
    {
        if ($value !== null && $value !== '' && in_array($key, $this->encryptedAttributes(), true)) {
            if ($hashColumn = $this->encryptedHashColumns()[$key] ?? null) {
                parent::setAttribute($hashColumn, $this->encrypter()->blindIndex($value));
            }

            $value = $this->encrypter()->encrypt($value);
        }

        return parent::setAttribute($key, $value);
    }

    /** The stored ciphertext, for the rare caller that needs it. */
    public function getRawAttributeValue(string $key): mixed
    {
        return parent::getAttribute($key);
    }

    /**
     * Looks a model up by the plaintext of an encrypted field:
     * Student::findByEncrypted('nik', $submitted).
     *
     * A plain where() on the encrypted column cannot work - the ciphertext
     * differs on every save - so this queries the deterministic hash instead.
     */
    public static function findByEncrypted(string $field, string $value): ?static
    {
        $hashColumn = (new static)->encryptedHashColumns()[$field] ?? null;

        if (! $hashColumn) {
            throw new InvalidArgumentException("No blind index configured for '{$field}' on ".static::class);
        }

        return static::query()
            ->where($hashColumn, app(FieldEncrypter::class)->blindIndex($value))
            ->first();
    }
}
