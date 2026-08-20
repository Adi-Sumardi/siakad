<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountScheme extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'code', 'name', 'type', 'value',
        'fee_type_id', 'school_unit_id', 'is_active', 'notes',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function studentDiscounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StudentDiscount::class);
    }

    /** What this scheme takes off a given subtotal, never more than the subtotal itself. */
    public function amountFor(float $subtotal): float
    {
        $value = (float) $this->value;

        $cut = $this->type === 'percent'
            ? $subtotal * ($value / 100)
            : $value;

        return round(min($cut, $subtotal), 2);
    }

    public function appliesTo(FeeType $type, Student $student): bool
    {
        if ($this->fee_type_id && $this->fee_type_id !== $type->id) {
            return false;
        }

        return ! $this->school_unit_id || $this->school_unit_id === $student->school_unit_id;
    }
}
