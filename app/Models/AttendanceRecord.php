<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id', 'attendance_session_id', 'classroom_id', 'term_id',
        'attendance_status', 'occurred_on', 'source', 'description',
        'recorded_by', 'record_status', 'revoked_by', 'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'revoked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->record_status === 'recorded';
    }

    public function scopeActive($query)
    {
        return $query->where('record_status', 'recorded');
    }

    /** Delegates to the student scope, so a change to who may see a child governs their attendance too. */
    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
    }
}
