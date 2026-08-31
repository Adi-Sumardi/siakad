<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentFeeSelection extends Model
{
    use HasUlidKey;

    protected $fillable = ['student_id', 'fee_rate_id', 'submitted_at', 'locked_at'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeRate(): BelongsTo
    {
        return $this->belongsTo(FeeRate::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudentFeeSelectionItem::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
