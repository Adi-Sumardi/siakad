<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One attendance window per unit per day per type (masuk/pulang) - normally
 * created by the scheduler from the unit's settings, never by hand. The
 * opens/closes/late_after values are snapshots: a settings edit tomorrow must
 * not rewrite what ran today.
 *
 * Wall-clock contract: opens_at/closes_at store Jakarta local time (the date
 * part is the Jakarta calendar date), so every comparison goes through
 * Carbon::now('Asia/Jakarta') - see the migration's docblock.
 */
class DailySession extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'school_unit_id', 'date', 'type',
        'opens_at', 'closes_at', 'late_after',
        'status', 'opened_by', 'closed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'late_after' => 'datetime:H:i',
        'closed_at' => 'datetime',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function dailyRecords(): HasMany
    {
        return $this->hasMany(DailyRecord::class);
    }

    public function isOpen(?Carbon $now = null): bool
    {
        return $this->status === 'open'
            && ($now ?? Carbon::now('Asia/Jakarta'))->lt($this->closes_at);
    }

    /**
     * The same scope line every staff-facing model draws: a per-unit admin (or
     * a teacher, who is unit-scoped like one) only ever resolves their own
     * unit's sessions - anything else is a 404, not a 403 (R3).
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user || ! $user->isUnitScoped()) {
            return $user && $user->isSuperAdmin()
                ? $query
                : $query->whereRaw('1 = 0');
        }

        return $user->school_unit_id
            ? $query->where('daily_sessions.school_unit_id', $user->school_unit_id)
            : $query->whereRaw('1 = 0');
    }
}
