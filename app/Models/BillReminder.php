<?php

namespace App\Models;

use App\Concerns\HasEncryptedAttributes;
use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillReminder extends Model
{
    use HasEncryptedAttributes, HasUlidKey;

    /**
     * Encrypted at rest (audit 2026-09-28): this column holds the byte-for-
     * byte same contact list notification_logs.recipient was encrypted for -
     * leaving it plaintext handed the leak the whole contact list anyway.
     * No blind index: rows are keyed by bill_id/kind, never by recipient.
     */
    protected $encrypted = ['sent_to'];

    protected $fillable = ['bill_id', 'kind', 'channel', 'sent_to', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
