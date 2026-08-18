<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillReminder extends Model
{
    use HasUlidKey;

    protected $fillable = ['bill_id', 'kind', 'channel', 'sent_to', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
