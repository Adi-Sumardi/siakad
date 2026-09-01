<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Extracurricular extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id', 'academic_year_id', 'name', 'description',
        'pembina_id', 'capacity', 'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function pembina(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pembina_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ExtracurricularMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    /** Central admin sees everything; a unit-scoped user sees their own unit's activities plus school-wide ones. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isUnitScoped()) {
            return $query->where(fn ($q) => $q->where('school_unit_id', $user->school_unit_id)->orWhereNull('school_unit_id'));
        }

        return $query->whereRaw('1 = 0');
    }
}
