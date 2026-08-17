<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use link that lets a guardian set their own password.
 *
 * The plaintext token lives in the email and nowhere else; only its hash is
 * stored, so a database leak yields no usable invitation link.
 */
class AccountInvitation extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'user_id',
        'token_hash',
        'channel',
        'sent_to',
        'purpose',
        'expires_at',
        'used_at',
        'sent_count',
        'last_sent_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'sent_count' => 'integer',
    ];

    public const TTL_DAYS = 7;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Hashing is plain SHA-256, not bcrypt: lookup happens by hash, so it must be deterministic. */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function generateToken(): string
    {
        return (string) Str::random(64);
    }

    public static function findByToken(string $plain): ?self
    {
        return static::where('token_hash', static::hashToken($plain))->first();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function markUsed(): void
    {
        $this->forceFill(['used_at' => now()])->save();
    }
}
