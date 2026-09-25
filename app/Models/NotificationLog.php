<?php

namespace App\Models;

use App\Concerns\HasEncryptedAttributes;
use App\Concerns\HasUlidKey;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class NotificationLog extends Model
{
    use HasEncryptedAttributes, HasUlidKey;

    /**
     * Encrypted at rest (audit T51-b): the recipient is a live contact for
     * every family in the school - the rest of the row is operational data,
     * this column alone was a plaintext contact list. No blind index: no
     * query ever looks rows up BY recipient.
     */
    protected $encrypted = ['recipient'];

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
     * The display form of a contact (audit T51-b): enough to recognize
     * ("sampai tidak? ke nomor mana?"), never enough to harvest - first
     * character + domain for emails, last four digits for phones.
     */
    public static function maskRecipient(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return '****'.mb_substr($value, -4);
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
