<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id',
        'academic_year_id',
        'tingkat',
        'name',
        'homeroom_teacher_id',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'tingkat' => 'integer',
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

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function classSchedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        // A teacher sees their own homeroom first, but still needs to see the
        // other rooms in their unit - they teach across them.
        if ($user->isUnitScoped()) {
            return $user->school_unit_id
                ? $query->where('classrooms.school_unit_id', $user->school_unit_id)
                : $query->whereRaw('1 = 0');
        }

        return $query->whereRaw('1 = 0');
    }
}
