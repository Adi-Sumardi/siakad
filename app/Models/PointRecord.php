<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointRecord extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id', 'term_id', 'point_rule_id', 'related_achievement_id', 'batch_id',
        'type', 'points', 'severity', 'occurred_on', 'description', 'evidence_path', 'evidence_name',
        'recorded_by', 'status', 'approval_stage', 'revoked_by', 'revoked_at', 'revoke_reason',
        'acknowledged_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'occurred_on' => 'date',
        'revoked_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function pointRule(): BelongsTo
    {
        return $this->belongsTo(PointRule::class);
    }

    public function relatedAchievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class, 'related_achievement_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Every stage's decision, oldest first. */
    public function approvals(): HasMany
    {
        return $this->hasMany(PointApproval::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status === 'recorded';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Waiting for someone - never in a balance, never on a parent's screen. */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** What a guardian may see: decided rows only (counted, or counted then revoked). */
    public function scopeFinal($query)
    {
        return $query->whereIn('status', ['recorded', 'revoked']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'recorded');
    }

    /** Delegates to the student scope, so a change to who may see a child governs their ledger too. */
    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
    }
}
