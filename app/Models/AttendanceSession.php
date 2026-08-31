<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'class_schedule_id', 'occurred_on', 'token', 'status',
        'opened_by', 'opened_at', 'closed_at', 'expires_at',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function classSchedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /** A session only accepts check-ins while it is still open and its lesson period hasn't ended. */
    public function isOpen(): bool
    {
        return $this->status === 'open' && now()->lt($this->expires_at);
    }
}
