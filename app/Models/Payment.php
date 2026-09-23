<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'payment_number', 'payer_guardian_id', 'amount', 'method', 'channel', 'status',
        'external_transaction_id', 'invoice_id', 'invoice_url',
        'gateway_response', 'metadata', 'expires_at', 'paid_at', 'failed_at',
        'verified_by', 'verified_at', 'verification_notes', 'rejection_reason', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'payer_guardian_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function bills()
    {
        return $this->belongsToMany(Bill::class, 'payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function isSettled(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * What a guardian may see: their own transactions. Staff see everything in
     * their unit, expressed through the bills the payment settled - a payment
     * has no unit of its own, and can span two.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isGuardian()) {
            return $query->whereHas('payer', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($user->isUnitScoped()) {
            return $query->whereHas('bills.student', fn ($q) => $q->visibleTo($user));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * The number a family and an admin see as "No. Referensi": the Virtual
     * Account itself, since that is the column e-SPP's own billing list shows
     * and what the bank prints on the transfer proof - so a receipt can be
     * matched against e-SPP by eye. The internal payment_number is only the
     * fallback for a payment that never got a VA.
     */
    public function referenceNumber(): string
    {
        return (string) ($this->gateway_response['va_number'] ?? $this->payment_number);
    }

    public static function generateNumber(): string
    {
        return 'PAY/'.now()->format('Ymd').'/'.mb_strtoupper(Str::random(6));
    }
}
