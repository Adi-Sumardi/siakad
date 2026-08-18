<?php

namespace App\Models;

use App\Concerns\HasEncryptedAttributes;
use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasEncryptedAttributes, HasFactory, HasUlidKey, SoftDeletes;

    protected $fillable = [
        'pmb_student_ulid',
        'no_pendaftaran',
        'nis',
        'nisn',
        'nik',
        'nama_lengkap',
        'nama_panggilan',
        'jenis_kelamin',
        'tempat_lahir',
        'tanggal_lahir',
        'agama',
        'kewarganegaraan',
        'golongan_darah',
        'alamat_lengkap',
        'rt',
        'rw',
        'kelurahan',
        'kecamatan',
        'kota_kabupaten',
        'provinsi',
        'kode_pos',
        'school_unit_id',
        'entry_year_id',
        'status',
        'status_notes',
        'status_changed_at',
        'photo_path',
    ];

    protected $hidden = ['nisn_hash', 'nik_hash'];

    protected $encrypted = ['nisn', 'nik'];

    protected $encryptedHashes = [
        'nisn' => 'nisn_hash',
        'nik' => 'nik_hash',
    ];

    protected $casts = [
        'tanggal_lahir' => 'date',
        'status_changed_at' => 'datetime',
    ];

    public function schoolUnit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class);
    }

    public function entryYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'entry_year_id');
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot(['relationship', 'is_primary', 'is_billing_contact'])
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class);
    }

    /** The room this student sits in for the active academic year, if placed. */
    public function currentEnrollment(): ?Enrollment
    {
        return $this->enrollments()
            ->where('status', 'active')
            ->with('classroom.homeroomTeacher')
            ->latest('joined_on')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Narrows a query to what the given staff account may see.
     *
     * One scope rather than a conditional in each controller: a screen that
     * forgets to call visibleTo() is visible in review, a missing `if` inside a
     * controller is not. That is the lesson PMB's Student::scopeVisibleTo
     * records, and it applies here to more roles, not fewer.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isUnitScoped()) {
            // Failing to "nothing" is the safe direction: an admin_unit whose
            // unit was never assigned sees an empty list, not the whole school.
            return $user->school_unit_id
                ? $query->where('students.school_unit_id', $user->school_unit_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->isGuardian()) {
            return $query->whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }
}
