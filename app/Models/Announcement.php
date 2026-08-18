<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scope is read narrowest-first: a classroom set means one room, a unit set
 * with no classroom means one unit, both null means the whole school. Pattern
 * and scopeLive() carried over from PMB's own Announcement.
 */
class Announcement extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id', 'classroom_id', 'title', 'body',
        'file_path', 'file_name', 'file_size', 'is_pinned',
        'created_by', 'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_pinned' => 'boolean',
        'file_size' => 'integer',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /** Published and due - a future published_at is scheduled, not live. */
    public function scopeLive($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * What a student's guardian may read: notices for their exact classroom,
     * their whole unit, or the whole school.
     */
    public function scopeForStudent($query, Student $student)
    {
        $classroomId = $student->currentEnrollment()?->classroom_id;

        return $query->where(function ($q) use ($student, $classroomId) {
            $q->whereNull('school_unit_id')->whereNull('classroom_id')
                ->orWhere(function ($q2) use ($student) {
                    $q2->where('school_unit_id', $student->school_unit_id)->whereNull('classroom_id');
                });

            if ($classroomId) {
                $q->orWhere('classroom_id', $classroomId);
            }
        });
    }

    /**
     * What a staff account may see on the admin screen: central admin,
     * everything; a per-unit admin, their own unit's notices plus the
     * school-wide ones - reading a notice their own families received should
     * never be a 404, even though writing to it is not theirs to do (see
     * scopeManageableBy below).
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isUnitScoped() && $user->school_unit_id) {
            return $query->where(function ($q) use ($user) {
                $q->where('school_unit_id', $user->school_unit_id)->orWhereNull('school_unit_id');
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * What a staff account may edit or delete: central admin, anything; a
     * per-unit admin, only their own unit's notices - never a school-wide one,
     * even though they can read it.
     */
    public function scopeManageableBy($query, ?User $user)
    {
        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isUnitScoped() && $user->school_unit_id) {
            return $query->where('school_unit_id', $user->school_unit_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
