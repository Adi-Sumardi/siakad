<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeType extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'code', 'name', 'recurrence', 'allow_installment',
        'requires_selection', 'requires_roster_membership', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'allow_installment' => 'boolean',
        'requires_selection' => 'boolean',
        'requires_roster_membership' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    public function isMonthly(): bool
    {
        return $this->recurrence === 'monthly';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
