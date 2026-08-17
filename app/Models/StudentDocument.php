<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id',
        'document_type',
        'file_path',
        'file_name',
        'file_size',
        'mime',
        'uploaded_by',
        'verified_at',
        'verified_by',
    ];

    protected $casts = ['verified_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
