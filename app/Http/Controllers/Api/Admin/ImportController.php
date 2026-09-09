<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Notification\PhoneNumberFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    /**
     * Import students, their classes, and their guardians from CSV.
     */
    public function importStudents(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'academic_year_ulid' => 'nullable|exists:academic_years,ulid',
        ]);

        $academicYear = $request->filled('academic_year_ulid')
            ? AcademicYear::where('ulid', $request->input('academic_year_ulid'))->first()
            : (AcademicYear::where('is_active', true)->first() ?? AcademicYear::latest('starts_on')->first());

        if (! $academicYear) {
            return response()->json(['message' => 'Tahun ajaran tidak ditemukan.'], 422);
        }

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return response()->json(['message' => 'Gagal membaca file CSV.'], 422);
        }

        // Read header
        $header = fgetcsv($handle, 2000, ',');
        if (! $header) {
            fclose($handle);
            return response()->json(['message' => 'File CSV kosong atau tidak valid.'], 422);
        }

        // Normalize header names
        $normalizedHeader = array_map(function ($col) {
            $cleaned = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', $col))));
            return match ($cleaned) {
                'nama', 'nama_siswa', 'nama_murid' => 'nama_lengkap',
                'nomor_induk', 'no_induk' => 'nis',
                'jk', 'gender', 'kelamin' => 'jenis_kelamin',
                'unit', 'sekolah', 'unit_sekolah', 'kode_unit' => 'unit_code',
                'rombel', 'ruang_kelas' => 'kelas',
                'nama_wali', 'orangtua', 'nama_orangtua', 'wali' => 'wali_nama',
                'no_hp', 'no_wa', 'telepon', 'hp_wali', 'wa_wali', 'kontak_wali' => 'wali_phone',
                'email_wali', 'email_orangtua' => 'wali_email',
                default => $cleaned,
            };
        }, $header);

        $requiredCols = ['nama_lengkap', 'unit_code'];
        foreach ($requiredCols as $req) {
            if (! in_array($req, $normalizedHeader, true)) {
                fclose($handle);
                return response()->json([
                    'message' => "Kolom wajib '{$req}' tidak ditemukan di baris header CSV. Kolom yang terdeteksi: " . implode(', ', $normalizedHeader),
                ], 422);
            }
        }

        $allUnits = SchoolUnit::all();
        $importedCount = 0;
        $updatedCount = 0;
        $errors = [];
        $rowNum = 1;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                $rowNum++;
                if (empty(array_filter($row))) {
                    continue;
                }

                $data = [];
                foreach ($normalizedHeader as $idx => $key) {
                    $data[$key] = isset($row[$idx]) ? trim($row[$idx]) : '';
                }

                $namaLengkap = $data['nama_lengkap'] ?? '';
                if (empty($namaLengkap)) {
                    $errors[] = "Baris {$rowNum}: Nama lengkap kosong, dilewati.";
                    continue;
                }

                // Match unit
                $unitCodeRaw = strtolower(trim($data['unit_code'] ?? ''));
                // Blank unit cell = "not given", never a silent match on the
                // first unit (str_contains with an empty needle is always true).
                $unit = $unitCodeRaw !== '' ? $allUnits->first(function ($u) use ($unitCodeRaw) {
                    return strtolower($u->code) === $unitCodeRaw ||
                           strtolower($u->label) === $unitCodeRaw ||
                           str_contains(strtolower($u->label), $unitCodeRaw);
                }) : null;

                if (! $unit) {
                    $errors[] = "Baris {$rowNum}: Unit sekolah '{$data['unit_code']}' tidak ditemukan.";
                    continue;
                }

                // Parse gender
                $jkRaw = strtoupper(substr($data['jenis_kelamin'] ?? 'L', 0, 1));
                $jk = in_array($jkRaw, ['L', 'P'], true) ? $jkRaw : 'L';

                // Find or create student
                $nis = ! empty($data['nis']) ? $data['nis'] : null;
                $student = null;

                if ($nis) {
                    $student = Student::where('school_unit_id', $unit->id)->where('nis', $nis)->first();
                }

                if (! $student) {
                    $student = Student::where('school_unit_id', $unit->id)
                        ->where('nama_lengkap', $namaLengkap)
                        ->first();
                }

                $isNew = false;
                if (! $student) {
                    $student = new Student();
                    $student->school_unit_id = $unit->id;
                    $student->entry_year_id = $academicYear->id;
                    $isNew = true;
                }

                $student->nama_lengkap = $namaLengkap;
                $student->nama_panggilan = $data['nama_panggilan'] ?? null;
                if ($nis) {
                    $student->nis = $nis;
                }
                if (! empty($data['nisn'])) {
                    $student->nisn = $data['nisn'];
                }
                $student->jenis_kelamin = $jk;
                $student->status = ! empty($data['status']) ? strtolower($data['status']) : 'active';
                $student->save();

                // Handle Classroom & Enrollment
                $kelasName = $data['kelas'] ?? '';
                if (! empty($kelasName)) {
                    // Try to parse tingkat e.g. "1-A" -> 1, "7B" -> 7, "TK-A" -> 0
                    preg_match('/\d+/', $kelasName, $matches);
                    $tingkat = ! empty($matches[0]) ? (int) $matches[0] : null;

                    $classroom = Classroom::firstOrCreate(
                        [
                            'school_unit_id' => $unit->id,
                            'academic_year_id' => $academicYear->id,
                            'name' => $kelasName,
                        ],
                        [
                            'tingkat' => $tingkat,
                            'is_active' => true,
                        ]
                    );

                    Enrollment::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'academic_year_id' => $academicYear->id,
                        ],
                        [
                            'classroom_id' => $classroom->id,
                            'status' => 'active',
                            'joined_on' => $academicYear->starts_on ?? now(),
                        ]
                    );
                }

                // Handle Guardian
                $waliNama = $data['wali_nama'] ?? '';
                $waliPhone = $data['wali_phone'] ?? '';
                $waliEmail = $data['wali_email'] ?? '';

                if (! empty($waliNama) || ! empty($waliPhone)) {
                    $guardianUser = null;

                    if (! empty($waliEmail) || ! empty($waliPhone)) {
                        // phone is an encrypted column (see HasEncryptedAttributes)
                        // - the ciphertext differs on every save, so a plain
                        // where('phone', ...) can never match and would silently
                        // create a fresh duplicate account on every import,
                        // including a re-import of the exact same file. Only
                        // findByEncrypted() (the blind-index hash column) can
                        // look one up by its plaintext value.
                        if (! empty($waliEmail)) {
                            $guardianUser = User::where('email', $waliEmail)->first();
                        }
                        if (! $guardianUser && ! empty($waliPhone)) {
                            $guardianUser = User::findByEncrypted('phone', $waliPhone);
                        }

                        if (! $guardianUser) {
                            $guardianUser = User::create([
                                'name' => $waliNama ?: 'Wali dari ' . $student->nama_lengkap,
                                'email' => ! empty($waliEmail) ? $waliEmail : null,
                                'phone' => ! empty($waliPhone) ? $waliPhone : null,
                                'role' => 'orangtua',
                                'is_active' => true,
                            ]);
                        }
                    }

                    // Same encrypted-column trap, and a second one:
                    // where('user_id', $guardianUser?->id) with a null id matches
                    // the first guardian row that also has no linked user - an
                    // unrelated family's orphaned guardian gets silently reused
                    // for this student whenever a row names a wali by name only.
                    $guardian = $guardianUser ? Guardian::where('user_id', $guardianUser->id)->first() : null;
                    if (! $guardian && ! empty($waliPhone)) {
                        $guardian = Guardian::findByEncrypted('no_hp', $waliPhone);
                    }

                    if (! $guardian) {
                        $guardian = Guardian::create([
                            'user_id' => $guardianUser?->id,
                            'nama' => $waliNama ?: ($guardianUser?->name ?? 'Wali Siswa'),
                            'hubungan' => ! empty($data['hubungan_wali']) ? strtolower($data['hubungan_wali']) : 'ayah',
                            'no_hp' => ! empty($waliPhone) ? $waliPhone : null,
                            'email' => ! empty($waliEmail) ? $waliEmail : null,
                        ]);
                    }

                    $rawRel = strtolower(trim($data['hubungan_wali'] ?? 'ayah'));
                    $relationship = in_array($rawRel, ['ayah', 'ibu', 'wali'], true) ? $rawRel : 'ayah';

                    // Exactly one primary/billing guardian per student is an
                    // invariant the rest of the app relies on (see
                    // PmbHandoffProcessor's own comment on the same rule, and
                    // every firstWhere('pivot.is_primary', true) that picks
                    // "the" guardian to bill or notify) - always marking this
                    // row's guardian primary without clearing any other would
                    // silently give a student two primaries the moment the
                    // same student is re-imported naming a different wali.
                    $student->guardians()->updateExistingPivot(
                        $student->guardians->pluck('id')->reject(fn ($id) => $id === $guardian->id)->all(),
                        ['is_primary' => false, 'is_billing_contact' => false],
                    );

                    $student->guardians()->syncWithoutDetaching([
                        $guardian->id => [
                            'relationship' => $relationship,
                            'is_primary' => true,
                            'is_billing_contact' => true,
                        ],
                    ]);
                }

                if ($isNew) {
                    $importedCount++;
                } else {
                    $updatedCount++;
                }
            }

            DB::commit();
            fclose($handle);

            ActivityLog::record($request->user(), 'students.imported', $academicYear, [
                'imported' => $importedCount,
                'updated' => $updatedCount,
            ]);

            return response()->json([
                'message' => "Proses impor selesai. {$importedCount} data siswa baru ditambahkan, {$updatedCount} data diperbarui.",
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            return response()->json([
                'message' => 'Terjadi kesalahan saat memproses data baris: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import fee rates from CSV.
     */
    public function importFeeRates(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return response()->json(['message' => 'Gagal membaca file CSV.'], 422);
        }

        $header = fgetcsv($handle, 2000, ',');
        if (! $header) {
            fclose($handle);
            return response()->json(['message' => 'File CSV kosong atau tidak valid.'], 422);
        }

        $normalizedHeader = array_map(function ($col) {
            $cleaned = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', $col))));
            return match ($cleaned) {
                'jenis_biaya', 'kode_biaya', 'jenis_tagihan' => 'fee_type_code',
                'unit', 'unit_sekolah', 'kode_unit' => 'unit_code',
                'tahun', 'tahun_ajaran', 'th_ajaran' => 'academic_year',
                'nominal', 'tarif', 'harga', 'biaya' => 'amount',
                'jatuh_tempo', 'tgl_jatuh_tempo' => 'due_day',
                'denda', 'nominal_denda' => 'late_fee_amount',
                default => $cleaned,
            };
        }, $header);

        $allTypes = FeeType::all();
        $allUnits = SchoolUnit::all();
        $allYears = AcademicYear::all();

        $importedCount = 0;
        $updatedCount = 0;
        $errors = [];
        $rowNum = 1;
        // Distinct unit codes actually touched - a CSV commonly spans several
        // units, so there is no single row's $unit that correctly represents
        // the whole import for the activity log below.
        $unitsTouched = [];

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                $rowNum++;
                if (empty(array_filter($row))) {
                    continue;
                }

                $data = [];
                foreach ($normalizedHeader as $idx => $key) {
                    $data[$key] = isset($row[$idx]) ? trim($row[$idx]) : '';
                }

                // Match Fee Type
                $typeCodeRaw = strtolower($data['fee_type_code'] ?? 'spp');
                $feeType = $allTypes->first(function ($t) use ($typeCodeRaw) {
                    return strtolower($t->code) === $typeCodeRaw ||
                           strtolower($t->name) === $typeCodeRaw;
                });

                if (! $feeType) {
                    $feeType = FeeType::create([
                        'code' => $typeCodeRaw,
                        'name' => ucwords(str_replace('_', ' ', $typeCodeRaw)),
                        'recurrence' => $typeCodeRaw === 'spp' ? 'monthly' : 'once',
                        'allow_installment' => true,
                    ]);
                    $allTypes->push($feeType);
                }

                // Match School Unit
                $unitCodeRaw = strtolower(trim($data['unit_code'] ?? ''));
                // Blank unit cell = "not given", never a silent match on the
                // first unit (str_contains with an empty needle is always true).
                $unit = $unitCodeRaw !== '' ? $allUnits->first(function ($u) use ($unitCodeRaw) {
                    return strtolower($u->code) === $unitCodeRaw ||
                           strtolower($u->label) === $unitCodeRaw ||
                           str_contains(strtolower($u->label), $unitCodeRaw);
                }) : null;

                if (! $unit) {
                    $errors[] = "Baris {$rowNum}: Unit sekolah '{$data['unit_code']}' tidak ditemukan.";
                    continue;
                }

                $unitsTouched[$unit->code] = true;

                // Match Academic Year
                $yearName = $data['academic_year'] ?? '2027/2028';
                $year = $allYears->first(fn ($y) => $y->year === $yearName);
                if (! $year) {
                    $year = AcademicYear::create([
                        'year' => $yearName,
                        'starts_on' => '2027-07-01',
                        'ends_on' => '2028-06-30',
                        'is_active' => false,
                    ]);
                    $allYears->push($year);
                }

                $amount = self::parseRupiah($data['amount'] ?? '0');
                $tingkat = ! empty($data['tingkat']) ? (int) $data['tingkat'] : null;
                $dueDay = ! empty($data['due_day']) ? (int) $data['due_day'] : 10;
                $lateFee = ! empty($data['late_fee_amount']) ? self::parseRupiah($data['late_fee_amount']) : 0;

                $rate = FeeRate::where('fee_type_id', $feeType->id)
                    ->where('school_unit_id', $unit->id)
                    ->where('academic_year_id', $year->id)
                    ->where('tingkat', $tingkat)
                    ->first();

                if ($rate) {
                    $rate->update([
                        'amount' => $amount,
                        'due_day' => $dueDay,
                        'late_fee_amount' => $lateFee,
                        'is_active' => true,
                    ]);
                    $updatedCount++;
                } else {
                    FeeRate::create([
                        'fee_type_id' => $feeType->id,
                        'school_unit_id' => $unit->id,
                        'academic_year_id' => $year->id,
                        'tingkat' => $tingkat,
                        'amount' => $amount,
                        'due_day' => $dueDay,
                        'late_fee_amount' => $lateFee,
                        'is_active' => true,
                    ]);
                    $importedCount++;
                }
            }

            DB::commit();
            fclose($handle);

            ActivityLog::record($request->user(), 'fee_rates.imported', null, [
                'imported' => $importedCount,
                'updated' => $updatedCount,
                'units' => array_keys($unitsTouched),
            ]);

            return response()->json([
                'message' => "Proses impor tarif selesai. {$importedCount} tarif baru dibuat, {$updatedCount} tarif diperbarui.",
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengimpor tarif: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import user accounts from CSV. Two shapes share one endpoint: a per-unit
     * admin bulk-onboards their own unit's teachers (role guru and their unit
     * are forced - the CSV's role/unit columns are ignored for them), while
     * the central admin imports any role, each row routed by its role and
     * unit_code columns exactly like the manual "Tambah Pengguna" form.
     * Re-importing the same file updates rather than duplicates, and an
     * email/number already owned by an account of a different role is an
     * error, never a silent role flip.
     */
    public function importUsers(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $caller = $request->user();

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return response()->json(['message' => 'Gagal membaca file CSV.'], 422);
        }

        $header = fgetcsv($handle, 2000, ',');
        if (! $header) {
            fclose($handle);
            return response()->json(['message' => 'File CSV kosong atau tidak valid.'], 422);
        }

        $normalizedHeader = array_map(function ($col) {
            $cleaned = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', $col))));
            return match ($cleaned) {
                'nama', 'nama_guru', 'nama_lengkap' => 'nama_lengkap',
                'no_hp', 'no_wa', 'nomor_hp', 'nomor_wa', 'telepon', 'wa', 'kontak' => 'no_hp',
                'unit', 'sekolah', 'unit_sekolah', 'kode_unit' => 'unit_code',
                'peran', 'role_pengguna', 'peran_pengguna' => 'role',
                'aktif', 'status', 'status_aktif', 'is_active' => 'is_aktif',
                default => $cleaned,
            };
        }, $header);

        if (! in_array('nama_lengkap', $normalizedHeader, true)) {
            fclose($handle);
            return response()->json([
                'message' => "Kolom wajib 'nama_lengkap' tidak ditemukan di baris header CSV. Kolom yang terdeteksi: " . implode(', ', $normalizedHeader),
            ], 422);
        }

        $allUnits = SchoolUnit::all();
        $importedCount = 0;
        $updatedCount = 0;
        $errors = [];
        $rowNum = 1;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                $rowNum++;
                if (empty(array_filter($row))) {
                    continue;
                }

                $data = [];
                foreach ($normalizedHeader as $idx => $key) {
                    $data[$key] = isset($row[$idx]) ? trim($row[$idx]) : '';
                }

                $nama = $data['nama_lengkap'] ?? '';
                if ($nama === '') {
                    $errors[] = "Baris {$rowNum}: Nama kosong, dilewati.";
                    continue;
                }

                // The role column is read for everyone, but a per-unit admin
                // may only onboard guru and orangtua accounts for their own
                // unit - any other role named in their file is an error, not
                // a silent downgrade. Unrecognised spellings are an error
                // too, never a guess. Blank means guru, the common case.
                $role = 'guru';
                if (! empty($data['role'])) {
                    $role = match (strtolower(trim($data['role']))) {
                        'admin', 'admin_pusat', 'administrator', 'pusat' => 'admin',
                        'admin_unit', 'tu', 'tata_usaha' => 'admin_unit',
                        'guru', 'guru_mapel' => 'guru',
                        'orangtua', 'wali', 'wali_murid' => 'orangtua',
                        default => null,
                    };

                    if ($role === null) {
                        $errors[] = "Baris {$rowNum}: Role '{$data['role']}' tidak dikenal (pilih: admin, admin_unit, guru, orangtua).";
                        continue;
                    }

                    if ($caller->isUnitScoped() && ! in_array($role, ['guru', 'orangtua'], true)) {
                        $errors[] = "Baris {$rowNum}: Admin unit hanya bisa impor guru atau wali murid - role '{$data['role']}' ditolak.";
                        continue;
                    }
                }

                // Whose unit this row lands in must never depend on the
                // contents of an uploaded file: a per-unit admin's own unit
                // always wins; the central admin follows the column, which
                // the unit-scoped roles require and admin never carries.
                $unit = null;
                if ($caller->isUnitScoped()) {
                    $unit = $caller->schoolUnit;
                } else {
                    $unitCodeRaw = strtolower(trim($data['unit_code'] ?? ''));
                    // str_contains() with an empty needle is always true, so
                    // a blank unit cell would silently match the FIRST unit -
                    // an empty column must be "unit not given", never that.
                    $unit = $unitCodeRaw !== '' ? $allUnits->first(function ($u) use ($unitCodeRaw) {
                        return strtolower($u->code) === $unitCodeRaw ||
                               strtolower($u->label) === $unitCodeRaw ||
                               str_contains(strtolower($u->label), $unitCodeRaw);
                    }) : null;

                    if (in_array($role, ['admin_unit', 'guru'], true) && ! $unit) {
                        $errors[] = "Baris {$rowNum}: Role {$role} wajib punya unit - unit '{$data['unit_code']}' tidak ditemukan.";
                        continue;
                    }

                    // An admin account is never unit-scoped; an orangtua's
                    // unit is optional metadata (it only steers whose list
                    // the parent shows up in).
                    if ($role === 'admin') {
                        $unit = null;
                    }
                }

                // Anything not explicitly "off" is active - a column left
                // blank means aktif, matching the form's checked-by-default.
                $isActiveRaw = strtolower(trim($data['is_aktif'] ?? ''));
                $isActive = ! in_array($isActiveRaw, ['0', 'tidak', 'nonaktif', 'false', 'no', 'n'], true);

                $email = ! empty($data['email']) ? mb_strtolower($data['email']) : null;
                // Stored normalised to 08xxxxxxxxxx: that is the form OTP
                // login normalises its identifier to before hashing, so a
                // number arriving as 62-format or bare digits would otherwise
                // create an account its own CSV says can log in, but can't.
                $phone = PhoneNumberFormatter::toWhatsAppFormat($data['no_hp'] ?? null);

                if (! $email && ! $phone) {
                    $errors[] = "Baris {$rowNum}: Email atau No HP harus diisi (untuk login OTP).";
                    continue;
                }

                if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Baris {$rowNum}: Email '{$email}' tidak valid.";
                    continue;
                }

                // phone is an encrypted column - only the blind-index lookup
                // can find an existing account by number (same note as in
                // importStudents()).
                $existing = null;
                if ($email) {
                    $existing = User::where('email', $email)->first();
                }
                if (! $existing && $phone) {
                    $existing = User::findByEncrypted('phone', $phone);
                }

                if ($existing) {
                    if ($existing->role !== $role) {
                        $errors[] = "Baris {$rowNum}: ".($email ?: $phone)." sudah dipakai akun {$existing->role} ({$existing->name}) - tidak diubah.";
                        continue;
                    }

                    // Only a guru's unit is identity: a per-unit admin must
                    // not pull another unit's teacher in. A parent's unit is
                    // just list metadata - PMB creates them with none, so the
                    // unit importing them may stamp its own.
                    if ($role === 'guru' && $caller->isUnitScoped() && $existing->school_unit_id !== $unit->id) {
                        $errors[] = "Baris {$rowNum}: Guru '{$nama}' terdaftar di unit lain - tidak diambil alih unit Anda.";
                        continue;
                    }

                    // Identity keys (email/phone) are the dedup match itself,
                    // so a re-import refreshes the name (and the unit where
                    // the caller may set it) - never the login channel.
                    $existing->fill(['name' => $nama])->save();
                    if (! $caller->isUnitScoped()) {
                        $existing->school_unit_id = $unit?->id;
                    } elseif ($existing->school_unit_id === null) {
                        $existing->school_unit_id = $unit?->id;
                    }
                    $existing->save();

                    if ($role === 'orangtua') {
                        self::ensureGuardianFor($existing, $phone, $email);
                    }
                    $updatedCount++;
                    continue;
                }

                $user = User::create([
                    'name' => $nama,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'school_unit_id' => $unit?->id,
                    'is_active' => $isActive,
                ]);

                // Same reason as UserController::store(): a parent account
                // needs its guardian row before any student can ever be
                // attached to it.
                if ($role === 'orangtua') {
                    self::ensureGuardianFor($user, $phone, $email);
                }
                $importedCount++;
            }

            DB::commit();
            fclose($handle);

            ActivityLog::record($caller, 'users.imported', null, [
                'imported' => $importedCount,
                'updated' => $updatedCount,
            ]);

            return response()->json([
                'message' => "Proses impor pengguna selesai. {$importedCount} akun baru ditambahkan, {$updatedCount} akun diperbarui.",
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengimpor akun pengguna: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * A parent account is only useful once a guardian row exists to attach
     * students to - the students CSV import later matches wali by phone/email
     * and links the children through it. Idempotent: an existing guardian
     * (e.g. PMB's own) is left untouched.
     */
    private static function ensureGuardianFor(User $user, ?string $phone, ?string $email): void
    {
        if (Guardian::where('user_id', $user->id)->exists()) {
            return;
        }

        Guardian::create([
            'user_id' => $user->id,
            'nama' => $user->name,
            'hubungan' => 'wali',
            'no_hp' => $phone,
            'email' => $email,
        ]);
    }

    /**
     * A rupiah amount typed or pasted from a spreadsheet, in whichever of the
     * two conventions the person filling out the CSV happens to use: plain
     * digits (650000), or grouped with a period the Indonesian way (650.000).
     * Stripping only non-digits, as this used to, treats the second form as
     * a decimal point - "650.000" becomes 650.0 rupiah instead of 650,000,
     * a thousand-fold under-bill that would go unnoticed until families
     * either pay far less than intended or the school's revenue doesn't
     * reconcile. A lone group of exactly three digits after the last
     * separator is grouping, not cents - rupiah has no subunit in practice
     * anywhere else in this codebase - so it's dropped along with any
     * comma; only a genuine one-or-two-digit remainder is kept as a decimal.
     */
    private static function parseRupiah(string $raw): float
    {
        $raw = trim($raw);

        if (preg_match('/^-?[\d.,]*[.,](\d{1,2})$/', $raw, $m) && strlen($m[1]) <= 2) {
            $normalized = preg_replace('/[.,](?=\d{1,2}$)/', '#', $raw);
            $normalized = str_replace(['.', ','], '', $normalized);
            $normalized = str_replace('#', '.', $normalized);

            return (float) $normalized;
        }

        return (float) preg_replace('/[^0-9-]/', '', $raw);
    }

    /**
     * Download student CSV template.
     */
    public function downloadStudentTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="template_import_siswa_siakad.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'nama_lengkap',
                'nis',
                'nisn',
                'jenis_kelamin',
                'unit_code',
                'kelas',
                'wali_nama',
                'wali_phone',
                'wali_email',
                'status',
            ]);

            // Sample rows
            fputcsv($handle, [
                'Muhammad Rayhan Pratama',
                '27001',
                '0012345678',
                'L',
                'sd',
                '1-A',
                'Bambang Sutrisno',
                '081234567890',
                'bambang@gmail.com',
                'active',
            ]);
            fputcsv($handle, [
                'Aisyah Putri Azzahra',
                '27002',
                '0012345679',
                'P',
                'smp',
                '7-B',
                'Hendra Gunawan',
                '081987654321',
                'hendra@gmail.com',
                'active',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Download the user-import CSV template, shaped for whoever asks: the
     * central admin's mirrors the "Tambah Pengguna" form (role + unit +
     * is_aktif columns, unit blank for the non-unit roles) and is filled
     * with the REAL unit names from the database - the same labels the
     * form's unit dropdown shows, RA/TK included, so nobody has to guess
     * codes. A per-unit admin gets the teacher-only shape their import
     * actually accepts (no unit column at all - it would be ignored).
     */
    public function downloadUserTemplate(Request $request): StreamedResponse
    {
        $isUnitScoped = $request->user()->isUnitScoped();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.($isUnitScoped ? 'template_import_guru_wali_siakad.csv' : 'template_import_pengguna_siakad.csv').'"',
        ];

        // Sample rows cite real units by their dropdown label, not made-up
        // codes - fetched inside the stream so the template always matches
        // whatever the unit master holds at download time.
        return response()->stream(function () use ($isUnitScoped) {
            $handle = fopen('php://output', 'w');

            if ($isUnitScoped) {
                // No unit column: their import lands every row in their own
                // unit. Role is the one choice left: their unit's teachers
                // and its parents.
                fputcsv($handle, ['nama_lengkap', 'email', 'no_hp', 'role']);

                fputcsv($handle, ['Ahmad Fauzi, S.Pd.', 'ahmad.fauzi@alazhar.sch.id', '081234567801', 'guru']);
                fputcsv($handle, ['Siti Rahmawati', '', '081234567802', 'guru']);
                fputcsv($handle, ['Hendra Gunawan', '', '081234567803', 'orangtua']);
            } else {
                $units = SchoolUnit::query()->orderBy('sort_order')->orderBy('label')->limit(3)->get()->values();
                $unitLabel = fn (int $i) => $units->get($i)?->label ?? '';

                fputcsv($handle, ['nama_lengkap', 'email', 'no_hp', 'role', 'unit_code', 'is_aktif']);

                fputcsv($handle, ['Ahmad Fauzi, S.Pd.', 'ahmad.fauzi@alazhar.sch.id', '081234567801', 'guru', $unitLabel(0), '1']);
                fputcsv($handle, ['Siti Rahmawati', '', '081234567802', 'guru', $unitLabel(1), '1']);
                fputcsv($handle, ['Rina Amalia, S.E.', 'rina.amalia@alazhar.sch.id', '', 'admin_unit', $unitLabel(2), '1']);
                // admin & orangtua are not tied to one unit - leave unit_code blank.
                fputcsv($handle, ['Yusuf Hanafi', 'yusuf.hanafi@yapinet.id', '', 'admin', '', '1']);
                fputcsv($handle, ['Wali Aisyah', 'wali.aisyah@gmail.com', '', 'orangtua', '', '1']);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Download fee rate CSV template.
     */
    public function downloadFeeRateTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="template_import_tarif_spp.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'fee_type_code',
                'unit_code',
                'tingkat',
                'academic_year',
                'amount',
                'due_day',
                'late_fee_amount',
            ]);

            // Sample rows
            fputcsv($handle, [
                'spp',
                'sd',
                '1',
                '2027/2028',
                '650000',
                '10',
                '0',
            ]);
            fputcsv($handle, [
                'spp',
                'smp',
                '',
                '2027/2028',
                '750000',
                '10',
                '0',
            ]);
            fputcsv($handle, [
                'uang_gedung',
                'sma',
                '',
                '2027/2028',
                '5000000',
                '15',
                '0',
            ]);

            fclose($handle);
        }, 200, $headers);
    }
}
