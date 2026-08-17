<?php

namespace App\Models;

use App\Concerns\HasEncryptedAttributes;
use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasEncryptedAttributes, HasFactory, HasUlidKey, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'school_unit_id',
        'is_active',
        'activated_at',
        'last_login_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'remember_token',
        'phone_hash',
    ];

    protected $encrypted = ['phone'];

    protected $encryptedHashes = ['phone' => 'phone_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * There is no password to return.
     *
     * Sessions are started with Auth::login() after a one-time code is checked,
     * so nothing here ever reaches credential validation. This override exists
     * so any framework path that reaches for a password gets an empty string it
     * cannot match, rather than a null it might mishandle.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    /** Classes this teacher is homeroom of. */
    public function homeroomClassrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'homeroom_teacher_id');
    }

    /** True for both admin kinds - what differs between them is scope, not entry. */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'admin_unit'], true);
    }

    /** Central admin: every unit, and the only role allowed to change settings. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Restricted to one unit's data. Guards against a misconfigured account:
     * an admin_unit with no unit must resolve to "nothing", never to "everything".
     */
    public function isUnitScoped(): bool
    {
        return in_array($this->role, ['admin_unit', 'guru'], true);
    }

    public function isGuardian(): bool
    {
        return $this->role === 'orangtua';
    }

    /**
     * The account exists from the moment PMB hands the student over; it counts
     * as activated once its owner has proved they hold the address it was sent
     * to - by opening the invitation, or by entering a sign-in code.
     *
     * Activation is not a gate on signing in: receiving a code proves the same
     * thing. It records when that first happened.
     */
    public function hasActivated(): bool
    {
        return $this->activated_at !== null;
    }
}
