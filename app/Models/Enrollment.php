<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id',
        'classroom_id',
        'academic_year_id',
        'status',
        'joined_on',
        'left_on',
        'absent_count',
        'sick_count',
        'permit_count',
    ];

    protected $casts = [
        'joined_on' => 'date',
        'left_on' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
