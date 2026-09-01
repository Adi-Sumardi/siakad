<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtracurricularMember extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'extracurricular_id', 'student_id', 'academic_year_id',
        'status', 'joined_on', 'left_on', 'assigned_by', 'assigned_at',
    ];

    protected $casts = [
        'joined_on' => 'date',
        'left_on' => 'date',
        'assigned_at' => 'datetime',
    ];

    public function extracurricular(): BelongsTo
    {
        return $this->belongsTo(Extracurricular::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
