<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guardian's izin/sakit notice for one or more days. Pending changes no
 * attendance; approval is what marks the covered masuk windows (see
 * LeaveRequestService). Never deleted - a withdrawn notice is 'cancelled'.
 */
class LeaveRequest extends Model
{
    use HasUlidKey;

    /** Longest single notice; anything longer is a matter for the school office, not a form. */
    public const MAX_DAYS = 14;

    /** How late a guardian may still file for a day already gone (surat sakit arriving after the weekend). */
    public const BACKDATE_DAYS = 7;

    protected $fillable = [
        'student_id', 'school_unit_id', 'requested_by',
        'type', 'date_from', 'date_to', 'reason',
        'attachment_path', 'attachment_name',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** Approved notices that cover the given date. */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
    }
}
