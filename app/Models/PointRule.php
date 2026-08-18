<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointRule extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id', 'code', 'name', 'type', 'category',
        'points', 'requires_evidence', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'points' => 'integer',
        'requires_evidence' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(PointRecord::class);
    }

    /** The signed value this rule writes to the ledger. */
    public function signedPoints(): int
    {
        return $this->type === 'violation' ? -abs($this->points) : abs($this->points);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Rules a given unit may use: its own plus the school-wide ones. */
    public function scopeForUnit($query, ?int $schoolUnitId)
    {
        return $query->where(function ($q) use ($schoolUnitId) {
            $q->whereNull('school_unit_id');

            if ($schoolUnitId) {
                $q->orWhere('school_unit_id', $schoolUnitId);
            }
        });
    }
}
