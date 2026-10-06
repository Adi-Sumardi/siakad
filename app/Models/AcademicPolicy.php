<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grade weights and watchlist thresholds (audit 6 Okt 2026 #11-12, T24).
 * Resolution: the unit's own row, else the school-wide row (null unit), else
 * DEFAULTS - which are exactly the constants the code used before, so an
 * empty table behaves as it always did.
 */
class AcademicPolicy extends Model
{
    public const DEFAULTS = [
        'weight_tugas' => 20,
        'weight_uts' => 30,
        'weight_uas' => 50,
        'kkm' => 70,
        'alpa_threshold' => 5,
        'grade_drop' => 5,
    ];

    protected $fillable = [
        'school_unit_id', 'weight_tugas', 'weight_uts', 'weight_uas',
        'kkm', 'alpa_threshold', 'grade_drop', 'updated_by',
    ];

    protected $casts = [
        'weight_tugas' => 'integer',
        'weight_uts' => 'integer',
        'weight_uas' => 'integer',
        'kkm' => 'integer',
        'alpa_threshold' => 'integer',
        'grade_drop' => 'integer',
    ];

    /** Container key of the per-request memo - the container is rebuilt per request (and per test). */
    private const MEMO = 'academic_policies.memo';

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    /** The effective policy for a unit (null = the school-wide one). Never null. */
    public static function forUnit(?int $unitId): self
    {
        if (! app()->bound(self::MEMO)) {
            app()->instance(self::MEMO, self::query()->get()->keyBy(fn (self $p) => (string) $p->school_unit_id)->all());
        }

        $memo = app(self::MEMO);

        return $memo[(string) $unitId]
            ?? $memo['']
            ?? new self(self::DEFAULTS);
    }

    /** @return array{tugas: float, uts: float, uas: float} fractions summing to 1 */
    public function weights(): array
    {
        return [
            'tugas' => $this->weight_tugas / 100,
            'uts' => $this->weight_uts / 100,
            'uas' => $this->weight_uas / 100,
        ];
    }

    /** What the dashboards quote next to their counts. */
    public function thresholds(): array
    {
        return [
            'kkm' => $this->kkm,
            'min_alpa' => $this->alpa_threshold,
            'grade_drop' => $this->grade_drop,
        ];
    }
}
