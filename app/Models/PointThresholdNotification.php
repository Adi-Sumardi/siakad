<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointThresholdNotification extends Model
{
    use \App\Concerns\HasUlidKey;

    protected $fillable = [
        'student_id', 'term_id', 'point_threshold_id', 'balance_at_notification', 'notified_at',
    ];

    protected $casts = [
        'balance_at_notification' => 'integer',
        'notified_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function threshold(): BelongsTo
    {
        return $this->belongsTo(PointThreshold::class, 'point_threshold_id');
    }
}
