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
        'password',
        'role',
        'school_unit_id',
        'is_active',
        'activated_at',
        'last_login_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
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
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
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
     * Deliberately not "has a password": guardians never get one.
     */
    public function hasActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Guardians sign in with a one-time code, staff with a password. The column
     * being null is what distinguishes them, so nothing has to keep a second
     * flag in step with it.
     */
    public function usesPasswordLogin(): bool
    {
        return $this->password !== null;
    }
}
