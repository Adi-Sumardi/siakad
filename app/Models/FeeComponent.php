<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeComponent extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'fee_rate_id', 'name', 'amount', 'default_qty',
        'is_optional', 'has_size_option', 'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'default_qty' => 'integer',
        'is_optional' => 'boolean',
        'has_size_option' => 'boolean',
    ];

    public function feeRate(): BelongsTo
    {
        return $this->belongsTo(FeeRate::class);
    }
}
