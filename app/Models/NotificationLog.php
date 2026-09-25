<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class NotificationLog extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'channel',
        'template',
        'recipient',
        'payload',
        'status',
        'attempts',
        'provider_message_id',
        'error',
        'sent_at',
        'claimed_at',
        'notifiable_type',
        'notifiable_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A raw increment expression for update() queries. Careful with the
     * row's contract: attempts DEFAULTS TO 1 - the send that created the
     * row is already counted - so a writer only increments for a RETRY
     * (its own second-plus attempt, or the sweep's), never for the first
     * physical send (audit T44).
     */
    public static function rawAttemptIncrement(): Expression
    {
        return DB::raw('attempts + 1');
    }

    /**
     * Atomically takes ownership of one delivery (audit T64-b). The old
     * already-sent check was read-then-send: two jobs holding the same row
     * (the sweep's re-queue racing the original job's backoff) both read
     * 'failed' and both physically sent. Stamping claimed_at through one
     * conditional UPDATE means exactly one caller wins - a 'sent' row
     * refuses, and a row whose claim is still fresh (another live job
     * mid-send) refuses too. Writing an outcome clears the claim so the
     * job's own retry re-claims cleanly; a worker that dies hard leaves
     * the claim to expire on its own after 30 minutes, after which the
     * retry sweep picks the row back up.
     */
    public static function claimDelivery(?string $ulid): bool
    {
        if (! $ulid) {
            // No row to guard - a caller that tracks nothing sends as before.
            return true;
        }

        return static::query()
            ->where('ulid', $ulid)
            ->where('status', '!=', 'sent')
            ->where(fn ($q) => $q
                ->whereNull('claimed_at')
                ->orWhere('claimed_at', '<', now()->subMinutes(30)))
            ->update(['claimed_at' => now()]) === 1;
    }
}
