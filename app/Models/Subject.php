<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasUlidKey;

    protected $fillable = ['school_unit_id', 'code', 'name', 'is_active', 'merged_into_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function classSchedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function tingkatRows(): HasMany
    {
        return $this->hasMany(SubjectTingkat::class)->orderBy('tingkat');
    }

    /** A subject with no tingkat rows predates per-tingkat subjects and applies everywhere. */
    public function appliesToTingkat(int $tingkat): bool
    {
        $rows = $this->relationLoaded('tingkatRows') ? $this->tingkatRows : $this->tingkatRows()->get();

        return $rows->isEmpty() || $rows->contains(fn (SubjectTingkat $r) => $r->tingkat === $tingkat && $r->is_active);
    }

    /** Duplicates folded into another subject by SubjectMerger stay on file but out of every list. */
    public function scopeNotMerged($query)
    {
        return $query->whereNull('merged_into_id');
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
