<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public static function current(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function label(): string
    {
        return ucfirst($this->name).' '.$this->academicYear?->year;
    }
}
