<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeAssignmentScore extends Model
{
    protected $fillable = ['grade_assignment_id', 'student_id', 'score', 'recorded_by'];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(GradeAssignment::class, 'grade_assignment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
