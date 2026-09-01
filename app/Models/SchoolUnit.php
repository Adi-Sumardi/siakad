<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class SchoolUnit extends Model
{
    use HasUlidKey;

    /**
     * Which jenjang a unit's own graduating pupils move up into - the
     * mathematical inverse of PMB's FEEDER_GROUPS (admissions asks "who
     * could you be coming from"; this asks "where do you go next"). SMA has
     * no entry because nothing in this app receives an SMA graduate - that's
     * the foundation's own exit point.
     */
    private const NEXT_JENJANG = [
        'ra' => 'tk', 'pg' => 'tk', 'tk' => 'sd', 'sd' => 'smp', 'smp' => 'sma', 'sma' => null,
    ];

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

    /** The jenjang this unit's graduates move into, or null at the foundation's own exit point (SMA). */
    public function nextJenjangGroup(): ?string
    {
        return self::NEXT_JENJANG[(string) $this->jenjang_group] ?? null;
    }

    /**
     * The foundation's own units a promoted student could land in next.
     * Empty at the top of the ladder (SMA), which is what tells a promotion
     * screen there is nowhere left to suggest - the student graduates
     * outright instead.
     *
     * @return Collection<int, self>
     */
    public function nextUnits(): Collection
    {
        $jenjang = $this->nextJenjangGroup();

        if (! $jenjang) {
            return collect();
        }

        return static::active()->where('jenjang_group', $jenjang)->ordered()->get(['id', 'code', 'label', 'jenjang_group']);
    }
}
