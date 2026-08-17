<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeRate extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'fee_type_id', 'school_unit_id', 'academic_year_id', 'tingkat', 'amount',
        'due_day', 'late_fee_amount', 'late_fee_grace_days',
        'effective_from', 'effective_to', 'is_active', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'late_fee_amount' => 'decimal:2',
        'tingkat' => 'integer',
        'due_day' => 'integer',
        'late_fee_grace_days' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(FeeComponent::class)->orderBy('sort_order');
    }

    /**
     * The rate that applies to a student for a fee, or null.
     *
     * A rate written for the student's exact level wins over the unit-wide one:
     * a school that prices kelas 1 differently has said something specific, and
     * the general row must not override it.
     */
    public static function resolve(FeeType $type, Student $student, AcademicYear $year, ?int $tingkat = null): ?self
    {
        return static::where('fee_type_id', $type->id)
            ->where('school_unit_id', $student->school_unit_id)
            ->where('academic_year_id', $year->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('tingkat', $tingkat)->orWhereNull('tingkat'))
            ->orderByRaw('CASE WHEN tingkat IS NULL THEN 1 ELSE 0 END')
            ->first();
    }
}
