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
        'payload' => 'array',
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
