<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeType extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'code', 'name', 'recurrence', 'allow_installment',
        'requires_selection', 'requires_roster_membership', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'allow_installment' => 'boolean',
        'requires_selection' => 'boolean',
        'requires_roster_membership' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Hanya uang pangkal yang boleh dicicil - SPP dan biaya lain dibayar
     * penuh per tagihan. Dipaksa saat simpan supaya form admin, seeder dan
     * impor tidak bisa menyalakannya untuk jenis biaya lain.
     */
    protected static function booted(): void
    {
        static::saving(function (FeeType $type) {
            $type->allow_installment = static::isUangPangkal($type->code, $type->name);
        });
    }

    public static function isUangPangkal(?string $code, ?string $name = null): bool
    {
        return str_contains(mb_strtolower(($code ?? '').' '.($name ?? '')), 'pangkal');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    public function isMonthly(): bool
    {
        return $this->recurrence === 'monthly';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
