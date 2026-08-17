<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingRun extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'fee_type_id', 'academic_year_id', 'term_id', 'school_unit_id', 'period_month',
        'status', 'bills_created', 'bills_skipped', 'total_amount', 'skipped_detail',
        'run_by', 'started_at', 'finished_at', 'error',
    ];

    protected $casts = [
        'period_month' => 'integer',
        'bills_created' => 'integer',
        'bills_skipped' => 'integer',
        'total_amount' => 'decimal:2',
        'skipped_detail' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
