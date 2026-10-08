<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One grade level a subject runs in; is_active = false deactivates the subject for that tingkat only. */
class SubjectTingkat extends Model
{
    protected $table = 'subject_tingkat';

    protected $fillable = ['subject_id', 'tingkat', 'is_active'];

    protected $casts = [
        'tingkat' => 'integer',
        'is_active' => 'boolean',
    ];
}
