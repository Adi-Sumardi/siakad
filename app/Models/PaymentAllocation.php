<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use HasUlidKey;

    protected $fillable = ['payment_id', 'bill_id', 'amount', 'applies_to_bill'];

    protected $casts = [
        'amount' => 'decimal:2',
        'applies_to_bill' => 'boolean',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
