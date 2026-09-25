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

    /**
     * Roster view of this classroom's enrollments (audit T49-d): active
     * only while the classroom belongs to the RUNNING year, everyone who
     * was ever enrolled once the year is past - promoting a cohort used to
     * empty the previous year's roster and every recap built on it, while
     * the data sat there untouched.
     */
    public function rosterEnrollments(): HasMany
    {
        $activeYearId = \App\Models\AcademicYear::current()?->id;

        return $this->enrollments()
            ->when(
                $activeYearId !== null && (int) $this->academic_year_id === (int) $activeYearId,
                fn ($q) => $q->where('status', 'active'),
            );
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
