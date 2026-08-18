<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Achievement extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'student_id', 'nama_prestasi', 'kategori', 'tingkat', 'juara',
        'nama_event', 'penyelenggara', 'tanggal_event', 'tempat_event',
        'sertifikat_path', 'foto_kegiatan_path', 'source', 'status', 'point_awarded',
        'recorded_by', 'verified_at', 'verified_by', 'rejection_reason',
    ];

    protected $casts = [
        'tanggal_event' => 'date',
        'point_awarded' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class, 'related_achievement_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** A row PMB already collected during registration - not this school's teacher's to edit. */
    public function isEditableHere(): bool
    {
        return $this->source === 'sekolah';
    }

    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
    }
}
