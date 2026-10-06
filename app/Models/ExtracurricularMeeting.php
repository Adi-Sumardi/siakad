<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One practice day of an ekskul (audit 6 Okt 2026 #8). */
class ExtracurricularMeeting extends Model
{
    use HasUlidKey;

    protected $fillable = ['extracurricular_id', 'date', 'notes', 'recorded_by'];

    protected $casts = ['date' => 'date'];

    public function extracurricular(): BelongsTo
    {
        return $this->belongsTo(Extracurricular::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ExtracurricularAttendance::class);
    }
}
