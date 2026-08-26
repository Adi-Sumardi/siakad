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
                $unitCodeRaw = strtolower($data['unit_code'] ?? '');
                $unit = $allUnits->first(function ($u) use ($unitCodeRaw) {
                    return strtolower($u->code) === $unitCodeRaw ||
                           strtolower($u->label) === $unitCodeRaw ||
                           str_contains(strtolower($u->label), $unitCodeRaw);
                });

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

                    // Link student & guardian pivot
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
                $unitCodeRaw = strtolower($data['unit_code'] ?? '');
                $unit = $allUnits->first(function ($u) use ($unitCodeRaw) {
                    return strtolower($u->code) === $unitCodeRaw ||
                           strtolower($u->label) === $unitCodeRaw ||
                           str_contains(strtolower($u->label), $unitCodeRaw);
                });

                if (! $unit) {
                    $errors[] = "Baris {$rowNum}: Unit sekolah '{$data['unit_code']}' tidak ditemukan.";
                    continue;
                }

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

            ActivityLog::record($request->user(), 'fee_rates.imported', $unit, [
                'imported' => $importedCount,
                'updated' => $updatedCount,
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
