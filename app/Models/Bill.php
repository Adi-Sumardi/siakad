<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'bill_number', 'student_id', 'academic_year_id', 'term_id',
        'fee_type_id', 'fee_rate_id', 'billing_run_id',
        'dedup_key', 'period_month', 'description',
        'subtotal', 'discount_amount', 'late_fee', 'total_amount',
        'paid_amount', 'remaining_amount', 'status',
        'due_date', 'grace_period_end', 'allow_installment',
        'issued_at', 'issued_by', 'paid_at', 'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'late_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'due_date' => 'date',
        'grace_period_end' => 'date',
        'allow_installment' => 'boolean',
        'period_month' => 'integer',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Statuses that still owe money - the definition every "outstanding" query shares. */
    public const OPEN_STATUSES = ['unpaid', 'partial', 'overdue'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function feeRate(): BelongsTo
    {
        return $this->belongsTo(FeeRate::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('sort_order');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class)->orderBy('sequence');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date->isPast();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * What the given account may see.
     *
     * Delegates to the student scope rather than restating the rule, so a
     * change to who may see a child automatically governs who may see their
     * bills - the two must never be able to disagree.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
    }

    /**
     * `SPP/2026/08/00412`. Readable on a bank transfer note, and the sequence
     * is per fee type and period rather than global, so a gap in one series
     * says nothing about another.
     */
    public static function generateNumber(FeeType $type, string $academicYear, ?int $month = null): string
    {
        $prefix = mb_strtoupper($type->code);
        $year = mb_substr($academicYear, 0, 4);
        $period = $month ? str_pad((string) $month, 2, '0', STR_PAD_LEFT) : null;

        $like = implode('/', array_filter([$prefix, $year, $period])).'/%';

        // The highest sequence actually in use, not how many rows happen to
        // match: count()+1 collided in production the first time a gap
        // opened up (two independent callers numbering the same fee
        // type/year/month, one seeded test data after the other had already
        // issued real bills) - count() stayed put while the taken numbers
        // did not, so it kept handing out one that was already claimed.
        $maxSequence = static::where('bill_number', 'like', $like)
            ->pluck('bill_number')
            ->map(fn (string $number) => (int) mb_substr($number, -5))
            ->max();

        $sequence = ($maxSequence ?? 0) + 1;

        return implode('/', array_filter([
            $prefix, $year, $period, str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        ]));
    }
}
