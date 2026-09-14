<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The daily attendance ledger row: one live mark per student per session
 * (enforced by a partial unique index, not by this class). Corrections are
 * revokes with a reason - never an UPDATE of the status, never a DELETE -
 * the same contract as attendance_records (D6), so an alpa corrected to
 * "sakit" after a parent calls in leaves an auditable trail.
 */
class DailyRecord extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'daily_session_id', 'student_id', 'classroom_id', 'term_id', 'date',
        'attendance_status', 'is_late', 'source', 'checked_in_at', 'device_hash', 'ip_hash',
        'recorded_by', 'description',
        'record_status', 'revoked_by', 'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'is_late' => 'boolean',
        'checked_in_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function dailySession(): BelongsTo
    {
        return $this->belongsTo(DailySession::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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

    /** Delegates to the student scope, so who may see a child governs their daily attendance too. */
    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
    }
}
