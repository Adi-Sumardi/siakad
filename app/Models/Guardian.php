<?php

namespace App\Models;

use App\Concerns\HasEncryptedAttributes;
use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guardian extends Model
{
    use HasEncryptedAttributes, HasFactory, HasUlidKey;

    protected $fillable = [
        'user_id',
        'nama',
        'hubungan',
        'no_hp',
        'email',
        'pekerjaan',
        'penghasilan_bulanan',
        'alamat',
    ];

    protected $hidden = ['no_hp_hash'];

    protected $encrypted = ['no_hp'];

    protected $encryptedHashes = ['no_hp' => 'no_hp_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')
            ->withPivot(['relationship', 'is_primary', 'is_billing_contact'])
            ->withTimestamps();
    }
}
