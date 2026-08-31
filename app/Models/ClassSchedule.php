<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSchedule extends Model
{
    use HasUlidKey;

    protected $fillable = ['classroom_id', 'subject_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time'];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    /** Same shape as Classroom::scopeVisibleTo - a schedule is only ever visible through its classroom's unit. */
    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->whereHas('classroom', fn ($q) => $q->visibleTo($user));
    }
}
