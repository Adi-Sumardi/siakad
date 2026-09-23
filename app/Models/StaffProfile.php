<?php

namespace App\Models;

use App\Concerns\HasEncryptedAttributes;
use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    use HasEncryptedAttributes, HasUlidKey;

    protected $fillable = ['user_id', 'nip', 'jabatan', 'photo_path', 'phone'];

    protected $hidden = ['phone_hash'];

    protected $encrypted = ['phone'];

    protected $encryptedHashes = ['phone' => 'phone_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mirrors the user's phone into the staff profile - the spec's staff
     * contact column (03-ERD: "data guru/staf yang tidak layak ditaruh di
     * users"). One field in the UI, two homes: the login identifier lives on
     * users.phone, the staff record's copy here. No-op for non-staff roles;
     * a parent's contact is the guardian row's business.
     */
    public static function mirrorUserPhone(User $user): ?self
    {
        if (! in_array($user->role, ['admin', 'admin_unit', 'guru'], true)) {
            return null;
        }

        $profile = static::firstOrNew(['user_id' => $user->id]);

        $profile->phone = $user->phone;

        if ($user->phone === null) {
            // The trait skips the hash sync on null writes (an empty value is
            // "not set", not "an empty string"), so a cleared phone needs the
            // stale hash removed by hand.
            $profile->phone_hash = null;
        }

        $profile->save();

        return $profile;
    }
}
