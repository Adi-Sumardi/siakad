<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'channel',
        'template',
        'recipient',
        'payload',
        'status',
        'provider_message_id',
        'error',
        'sent_at',
        'notifiable_type',
        'notifiable_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
