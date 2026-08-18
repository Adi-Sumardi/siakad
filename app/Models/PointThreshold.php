<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointThreshold extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id', 'min_points', 'max_points', 'label', 'action', 'color', 'notify_guardian',
    ];

    protected $casts = [
        'min_points' => 'integer',
        'max_points' => 'integer',
        'notify_guardian' => 'boolean',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    /**
     * The band a balance falls into, for the given unit - that unit's own
     * bands first, falling back to the school-wide ones so a unit that never
     * set its own still gets badges and notifications.
     */
    public static function forBalance(int $balance, ?int $schoolUnitId): ?self
    {
        $query = static::where('min_points', '<=', $balance)->where('max_points', '>=', $balance);

        if ($schoolUnitId) {
            $own = (clone $query)->where('school_unit_id', $schoolUnitId)->first();

            if ($own) {
                return $own;
            }
        }

        return $query->whereNull('school_unit_id')->first();
    }
}
