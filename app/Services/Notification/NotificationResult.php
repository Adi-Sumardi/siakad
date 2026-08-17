<?php

namespace App\Services\Notification;

class NotificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly ?array $raw = null,
    ) {
    }

    public static function ok(?array $raw = null): self
    {
        return new self(true, null, $raw);
    }

    public static function fail(string $message, ?array $raw = null): self
    {
        return new self(false, $message, $raw);
    }
}
