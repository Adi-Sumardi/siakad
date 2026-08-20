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
        'achiever_type', 'student_id', 'teacher_user_id', 'school_unit_id',
        'nama_prestasi', 'kategori', 'tingkat', 'juara',
        'nama_event', 'penyelenggara', 'tanggal_event', 'tempat_event',
        'sertifikat_path', 'sertifikat_name', 'foto_kegiatan_path', 'foto_kegiatan_name', 'source', 'status', 'point_awarded',
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
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
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isUnitScoped()) {
            return $query->where(function ($q) use ($user) {
                $q->whereHas('student', fn ($sq) => $sq->where('school_unit_id', $user->school_unit_id))
                    ->orWhere('school_unit_id', $user->school_unit_id)
                    ->orWhereHas('teacher', fn ($tq) => $tq->where('school_unit_id', $user->school_unit_id));
            });
        }

        if ($user->isGuardian()) {
            return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
        }

        return $query->whereRaw('1 = 0');
    }
}
