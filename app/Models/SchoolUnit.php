<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolUnit extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'code',
        'label',
        'jenjang_group',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /**
     * Resolves the unit a PMB payload refers to.
     *
     * Matching is on `code` alone, on purpose. PMB pairs units by normalised
     * label text because its own column is free text; here the code is the
     * contract, so renaming "SD Sakinah" to "SD Islam Sakinah" in either app
     * cannot quietly detach a cohort of students.
     */
    public static function findByCode(?string $code): ?self
    {
        if (! $code) {
            return null;
        }

        return static::where('code', $code)->first();
    }
}
