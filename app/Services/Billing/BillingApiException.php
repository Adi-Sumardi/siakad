<?php

namespace App\Services\Billing;

use RuntimeException;

/** Raised when the Billing API rejects a request or a login attempt. */
class BillingApiException extends RuntimeException
{
    /** @param array<string, array<int, string>> $errors Field-level validation errors, when the API returned any. */
    public function __construct(string $message, private readonly int $statusCode, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, array<int, string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
