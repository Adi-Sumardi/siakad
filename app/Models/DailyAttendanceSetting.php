<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit's own bell schedule and gate policy for the daily attendance layer
 * (DESAIN-PRESENSI-HARIAN.md). One row per unit, edited by that unit's admin;
 * `enabled` starts false so a campus is never surprised by auto-alpa
 * notifications before it has configured and switched itself on.
 */
class DailyAttendanceSetting extends Model
{
    use HasUlidKey;

    /** The table is 'daily_settings', not the class-derived 'daily_attendance_settings'. */
    protected $table = 'daily_settings';

    /** The mode a jenjang starts with, before a unit overrides it: little ones (and SD, whose only teacher is the homeroom teacher) get marked in class; SMP/SMA run the gate. */
    private const MODE_BY_JENJANG = [
        'pg' => 'wali_kelas', 'ra' => 'wali_kelas', 'tk' => 'wali_kelas', 'sd' => 'wali_kelas',
        'smp' => 'gerbang', 'sma' => 'gerbang',
    ];

    protected $fillable = [
        'school_unit_id', 'enabled', 'days',
        'masuk_opens_at', 'masuk_closes_at', 'masuk_late_after',
        'pulang_enabled', 'pulang_opens_at', 'pulang_closes_at',
        'intake_mode',
        'geo_required', 'gate_lat', 'gate_lng', 'geo_radius_m',
        'qr_required', 'public_slug',
        'notify_masuk', 'notify_pulang', 'notify_absent',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'days' => 'array',
        'pulang_enabled' => 'boolean',
        'intake_mode' => 'string',
        'geo_required' => 'boolean',
        'qr_required' => 'boolean',
        'notify_masuk' => 'boolean',
        'notify_pulang' => 'boolean',
        'notify_absent' => 'boolean',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DailySession::class, 'school_unit_id', 'school_unit_id');
    }

    public static function defaultModeFor(SchoolUnit $unit): string
    {
        return self::MODE_BY_JENJANG[(string) $unit->jenjang_group] ?? 'wali_kelas';
    }

    // The API accepts "HH:MM" (the natural shape of a time input); the table
    // stores "HH:MM:SS" so comparisons and snapshots stay uniform.
    public function setMasukOpensAtAttribute($value): void
    {
        $this->attributes['masuk_opens_at'] = $this->padTime($value);
    }

    public function setMasukClosesAtAttribute($value): void
    {
        $this->attributes['masuk_closes_at'] = $this->padTime($value);
    }

    public function setMasukLateAfterAttribute($value): void
    {
        $this->attributes['masuk_late_after'] = $this->padTime($value);
    }

    public function setPulangOpensAtAttribute($value): void
    {
        $this->attributes['pulang_opens_at'] = $this->padTime($value);
    }

    public function setPulangClosesAtAttribute($value): void
    {
        $this->attributes['pulang_closes_at'] = $this->padTime($value);
    }

    private function padTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^\d{2}:\d{2}$/', $value) ? $value.':00' : $value;
    }

    /** ISO 1=Monday..7=Sunday, matching ClassroomController's day-of-week convention. */
    public function runsOn(int $dayOfWeekIso): bool
    {
        return in_array($dayOfWeekIso, $this->days ?? [], false);
    }
}
