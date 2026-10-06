<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtracurricularAttendance extends Model
{
    protected $fillable = ['extracurricular_meeting_id', 'extracurricular_member_id', 'status'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ExtracurricularMeeting::class, 'extracurricular_meeting_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ExtracurricularMember::class, 'extracurricular_member_id');
    }
}
