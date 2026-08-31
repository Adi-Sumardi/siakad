<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subject extends Model
{
    use HasUlidKey;

    protected $fillable = ['school_unit_id', 'code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** School-wide subjects (null school_unit_id) plus this unit's own. */
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
