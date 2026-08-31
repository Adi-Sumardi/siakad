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
        'is_optional', 'has_size_option', 'size_options', 'sort_order',
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

    /** Admin-defined dropdown values, e.g. "S,M,L,XL" -> ["S","M","L","XL"]. */
    public function sizeOptionList(): array
    {
        if (! $this->size_options) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->size_options))));
    }
}
