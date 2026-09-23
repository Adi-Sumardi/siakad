<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Term extends Model
{
    use HasUlidKey;

    protected $fillable = ['academic_year_id', 'name', 'starts_on', 'ends_on', 'is_active'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * The one semester every write path (grades, points, achievements,
     * rapor defaults) files under.
     *
     * Ordered by starts_on, not insertion luck: two lit rows can only exist
     * through a data mistake or a half-finished manual edit, and "the one
     * that starts latest" is the defensible pick either way - a plain
     * ->first() returned whichever row happened to sit at the top.
     */
    public static function current(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Makes this the one active semester - the December/July flip.
     *
     * "Clear all, then set one" inside a transaction, same shape as
     * AcademicYear::activate(): two active terms would make every
     * Term::current() reader ambiguous, and the first readers are the
     * grade input lane and the SPP generator.
     */
    public function activate(): void
    {
        DB::transaction(function () {
            static::query()->where('is_active', true)->update(['is_active' => false]);
            static::whereKey($this->getKey())->update(['is_active' => true]);
        });
    }

    public function label(): string
    {
        return ucfirst($this->name).' '.$this->academicYear?->year;
    }
}
