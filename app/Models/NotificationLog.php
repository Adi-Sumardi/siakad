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
        'notifiable_type',
        'notifiable_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
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
}
