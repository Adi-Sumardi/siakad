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
}
