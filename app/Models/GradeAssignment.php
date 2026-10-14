<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One assignment column (Tugas 1, Tugas 2, ...) of a (classroom, subject, term) gradebook. */
class GradeAssignment extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'classroom_id', 'subject_id', 'term_id', 'title', 'assigned_on', 'created_by',
    ];

    protected $casts = [
        'assigned_on' => 'date',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(GradeAssignmentScore::class);
    }
}
