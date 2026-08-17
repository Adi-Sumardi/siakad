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
     */
    public function activate(): void
    {
        DB::transaction(function () {
            static::query()->where('is_active', true)->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
        });
    }

    public function activeTerm(): ?Term
    {
        return $this->terms()->where('is_active', true)->first();
    }
}
