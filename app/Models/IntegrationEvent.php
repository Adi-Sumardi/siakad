<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEvent extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'source',
        'event_type',
        'event_id',
        'payload',
        'status',
        'student_id',
        'processed_at',
        'attempts',
        'error',
    ];

    protected $casts = [
        // Encrypted JSON at rest (T51-b): PMB handoff payloads carry
        // children's and guardians' PII. Rows are keyed by source+event_id
        // and never looked up by payload content, so no blind index.
        'payload' => 'encrypted:array',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function markProcessed(?Student $student = null): void
    {
        $this->forceFill([
            'status' => 'processed',
            'student_id' => $student?->id ?? $this->student_id,
            'processed_at' => now(),
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => 'failed',
            'attempts' => $this->attempts + 1,
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    /**
     * Closes the loop for a settled payment (audit 2026-10-05): a webhook
     * verification that failed - network hiccup, malformed sisa, amount
     * mismatch - leaves a 'failed' row behind forever, even when the
     * poller settles the very same payment minutes later. Permanent noise
     * buries the rows that genuinely need a human (overpayments, surprise
     * late payments), so after any successful settle the lanes call this
     * to mark that payment's stale failed callbacks processed.
     *
     * Matching is done in PHP on the decrypted payload: the column is
     * encrypted, so there is no JSON grammar to fight with and the failed
     * set is small by construction.
     */
    public static function resolveFailedCallbacksFor(Payment $payment): int
    {
        $carriedIds = array_values(array_unique(array_filter([
            $payment->external_transaction_id,
            $payment->invoice_id,
            $payment->gateway_response['billing_uuid'] ?? null,
            $payment->gateway_response['va_number'] ?? null,
            $payment->payment_number,
        ], fn ($v) => is_string($v) && $v !== '')));

        $resolved = 0;

        static::query()
            ->where('source', 'billing_api')
            ->where('event_type', 'payment.callback')
            ->where('status', 'failed')
            ->chunkById(100, function ($rows) use ($carriedIds, &$resolved) {
                foreach ($rows as $row) {
                    $payload = $row->payload ?? [];

                    $billingUuid = $payload['billing_uuid'] ?? null;
                    if (is_array($billingUuid)) {
                        $billingUuid = $billingUuid['string'] ?? null;
                    }

                    $eventCarried = array_filter([
                        $billingUuid,
                        $payload['uuid'] ?? null,
                        $payload['reference_no'] ?? null,
                    ]);

                    if (array_intersect($eventCarried, $carriedIds) !== []) {
                        $row->markProcessed();
                        $resolved++;
                    }
                }
            });

        return $resolved;
    }
}
