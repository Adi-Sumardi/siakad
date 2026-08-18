<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointRecord extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id', 'term_id', 'point_rule_id', 'related_achievement_id',
        'type', 'points', 'occurred_on', 'description', 'evidence_path', 'evidence_name',
        'recorded_by', 'status', 'revoked_by', 'revoked_at', 'revoke_reason',
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

    public function isActive(): bool
    {
        return $this->status === 'recorded';
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
