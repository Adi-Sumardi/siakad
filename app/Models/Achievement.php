<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One trophy on the cabinet: nama, kategori, tingkat, juara, evidence.
 *
 * Two orthogonal dimensions the audit once flagged (G.3) and the design
 * deliberately keeps apart: `source` is PROVENANCE - pmb (imported already
 * verified, not ours to edit, see isEditableHere()) vs sekolah (recorded
 * here) - while `achiever_type` is WHOSE achievement it is, siswa or guru.
 * "guru" is intentionally not a `source` value: a teacher's achievement is
 * source=sekolah + achiever_type=guru, and folding both meanings into one
 * column is exactly what would make them rancu. Confirmed as the final
 * design, 16 Sep 2026.
 */
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

    /**
     * An identical proposal already waiting for a decision (follow-up to
     * the Poin 6 bug report): the same win filed twice - a double-clicked
     * submit, wali and guru both reporting it - must not stack pending
     * cards that each carry their own points at verify time. Name + event
     * date identify "the same win"; the verifier can still differentiate
     * genuinely different wins by naming them differently.
     */
    public static function pendingDuplicateExists(?int $studentId, ?int $teacherUserId, string $namaPrestasi, ?string $tanggalEvent): bool
    {
        return static::query()
            ->where('status', 'pending')
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId), fn ($q) => $q->whereNull('student_id'))
            ->when($teacherUserId, fn ($q) => $q->where('teacher_user_id', $teacherUserId))
            ->where('nama_prestasi', $namaPrestasi)
            // whereDate, never a bare string comparison: the model casts
            // tanggal_event to datetime, so SQLite stores '2026-08-20
            // 00:00:00' and `= '2026-08-20'` silently matches nothing -
            // the same trap that made the old seeder stack duplicates.
            ->when($tanggalEvent, fn ($q) => $q->whereDate('tanggal_event', $tanggalEvent), fn ($q) => $q->whereNull('tanggal_event'))
            ->exists();
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
