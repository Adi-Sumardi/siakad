<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    use HasUlidKey;

    protected $fillable = ['user_id', 'nip', 'jabatan', 'photo_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
