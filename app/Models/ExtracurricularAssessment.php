<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The pembina's per-term predikat for one member - what the rapor prints (audit 6 Okt 2026 #8). */
class ExtracurricularAssessment extends Model
{
    use HasUlidKey;

    public const PREDIKAT = [
        'A' => 'Sangat Baik',
        'B' => 'Baik',
        'C' => 'Cukup',
        'D' => 'Kurang',
    ];

    protected $fillable = ['extracurricular_member_id', 'term_id', 'predikat', 'keterangan', 'assessed_by'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(ExtracurricularMember::class, 'extracurricular_member_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
