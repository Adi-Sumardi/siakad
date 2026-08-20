<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class StudentDiscount extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id', 'discount_scheme_id', 'academic_year_id',
        'effective_from', 'effective_to', 'reason', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(DiscountScheme::class, 'discount_scheme_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** In force on a given date - a scholarship that ended in June must not touch a July bill. */
    public function scopeEffectiveOn($query, Carbon $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
