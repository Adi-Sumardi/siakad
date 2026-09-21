<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class AcademicYear extends Model
{
    use HasUlidKey;

    protected $fillable = ['year', 'starts_on', 'ends_on', 'is_active'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    public static function current(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Makes this the one active year.
     *
     * Wrapped in a transaction and written as "clear all, then set one" because
     * two active years would make every "current year" query ambiguous - and the
     * first thing that reads it is the SPP generator.
     *
     * A year rolling over also closes the OLD year's still-lit semesters:
     * grades and points file under Term::current(), and an old term left
     * active would keep collecting writes nobody reads while the new year
     * has no open semester yet (an honest "no active semester" beats a
     * silently wrong one).
     */
    public function activate(): void
    {
        DB::transaction(function () {
            static::query()->where('is_active', true)->update(['is_active' => false]);
            static::whereKey($this->getKey())->update(['is_active' => true]);

            Term::query()
                ->where('is_active', true)
                ->where('academic_year_id', '!=', $this->getKey())
                ->update(['is_active' => false]);
        });
    }

    public function activeTerm(): ?Term
    {
        return $this->terms()->where('is_active', true)->first();
    }
}
