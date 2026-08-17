<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginOtp extends Model
{
    use HasUlidKey;

    /** Long enough to arrive and be typed, short enough that a stolen code goes stale. */
    public const TTL_MINUTES = 10;

    /** Wrong guesses allowed before the code is burned. */
    public const MAX_ATTEMPTS = 5;

    /** Minimum gap between sends, so "kirim ulang" cannot be used to spam someone. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    protected $fillable = [
        'user_id',
        'identifier',
        'channel',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Deterministic, because verification looks the code up by hash. */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }

    public function secondsUntilResendAllowed(): int
    {
        $elapsed = $this->created_at->diffInSeconds(now());

        return (int) max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }
}
