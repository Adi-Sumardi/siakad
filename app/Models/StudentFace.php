<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's reference face for gate check-in (docs/09-ABSEN-WAJAH-GERBANG.md).
 * Enrolled automatically on the student's first face check-in; a wali kelas
 * or unit staff can delete it so the next check-in enrolls a fresh one.
 *
 * The descriptor is 128 floats from the browser's face model. It is stored
 * encrypted and never leaves the server again - the match happens here.
 */
class StudentFace extends Model
{
    use HasUlidKey;

    protected $fillable = ['student_id', 'descriptor', 'photo_path', 'enrolled_at'];

    protected $casts = [
        'descriptor' => 'encrypted:array',
        'enrolled_at' => 'datetime',
    ];

    protected $hidden = ['descriptor'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
