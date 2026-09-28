<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointThreshold extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id', 'min_points', 'max_points', 'label', 'action', 'color',
    ];

    protected $casts = [
        'min_points' => 'integer',
        'max_points' => 'integer',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    /**
     * The band a balance falls into, for the given unit - that unit's own
     * bands first, falling back to the school-wide ones so a unit that never
     * set its own still gets badges.
     *
     * Ordered by min_points DESC so overlapping bands resolve
     * deterministically to the TIGHTEST one (audit 2026-09-28: unordered
     * first() made the badge/threshold filter depend on row order).
     */
    public static function forBalance(int $balance, ?int $schoolUnitId): ?self
    {
        $query = static::where('min_points', '<=', $balance)
            ->where('max_points', '>=', $balance)
            ->orderByDesc('min_points');

        if ($schoolUnitId) {
            $own = (clone $query)->where('school_unit_id', $schoolUnitId)->first();

            if ($own) {
                return $own;
            }
        }

        return $query->whereNull('school_unit_id')->first();
    }

    /**
     * Whether this band means trouble: anything but an explicitly "good"
     * band counts. The KPI cards and the ?flagged filter use this so a
     * school that configures a green "Aman" band does not flag the whole
     * roster as SP cases (audit 2026-09-28).
     */
    public function isWarningBand(): bool
    {
        return $this->color !== 'good';
    }
}
