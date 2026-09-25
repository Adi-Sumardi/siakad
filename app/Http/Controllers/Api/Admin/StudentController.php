<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Services\Export\DapodikExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    /**
     * List all students per unit, per jenjang, with SPP rates and active discounts for specified/active academic year.
     */
    public function index(Request $request): JsonResponse
    {
        $yearInput = $request->string('academic_year')->value() ?: $request->string('year')->value();

        $selectedYear = null;
        if (! empty($yearInput)) {
            $selectedYear = AcademicYear::where('year', $yearInput)->first()
                ?? AcademicYear::where('ulid', $yearInput)->first();
        }

        if (! $selectedYear) {
            $selectedYear = AcademicYear::where('is_active', true)->first()
                ?? AcademicYear::latest('starts_on')->first();
        }

        $sppType = FeeType::where('code', 'spp')->first();

        // One filter set feeds both the paginated rows and the KPI totals -
        // the summary cards describe the whole filtered cohort, not just the
        // 20 rows on the current page.
        $filteredQuery = fn () => Student::query()
            ->visibleTo($request->user())
            ->when($request->string('search')->value(), function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('nama_panggilan', 'like', "%{$search}%")
                        ->orWhere('nis', 'like', "%{$search}%")
                        ->orWhere('no_pendaftaran', 'like', "%{$search}%");
                });
            })
            ->when($request->string('unit')->value(), fn ($q, $unitCode) => $q->whereHas('schoolUnit', fn ($uq) => $uq->where('code', $unitCode)))
            // Jenjang comes in two granularities off one ladder
            // (feature batch Poin 1): a coarse group key (tk|sd|smp|sma -
            // the historical values, kept working) filters the unit's
            // group; a granular key (sd-3, tk-a, pg-sb…) additionally
            // matches the classroom's tingkat and, for early childhood,
            // its name prefix. Granular goes through the student's ACTIVE
            // ENROLLMENT in the selected year - an unplaced student has no
            // rung to sit on and stays outside the granular result by
            // design (coarse still catches them).
            ->when($request->string('jenjang')->value(), function ($q, $jenjang) use ($selectedYear) {
                $q->where(function ($jq) use ($jenjang, $selectedYear) {
                    $group = \App\Support\Jenjang::groupOf($jenjang);
                    $jq->whereHas('schoolUnit', fn ($uq) => $uq->where('jenjang_group', $group));

                    if (\App\Support\Jenjang::isValid($jenjang) && ! in_array($jenjang, \App\Support\Jenjang::GROUPS, true)) {
                        $jq->whereHas('enrollments', function ($eq) use ($jenjang, $selectedYear) {
                            $eq->where('status', 'active')
                                ->where('academic_year_id', $selectedYear?->id)
                                ->whereHas('classroom', fn ($cq) => \App\Support\Jenjang::applyToClassroomQuery($cq, $jenjang));
                        });
                    }
                });
            })
            ->when($request->string('status')->value(), fn ($q, $status) => $q->where('status', $status))
            // The dashboard's "belum ditempatkan di kelas" alert links here
            // with placement=none - same definition that alert counts:
            // no active enrollment in the SELECTED year (default = active
            // year), so a student deliberately between rombels for a future
            // year is not reported as unplaced.
            ->when($request->string('placement')->value() === 'none', fn ($q) => $q
                ->whereDoesntHave('enrollments', fn ($eq) => $eq->where('status', 'active')
                    ->when($selectedYear, fn ($ey) => $ey->where('academic_year_id', $selectedYear->id))))
            ->orderBy('nama_lengkap');

        $students = $filteredQuery()
            ->with([
                'schoolUnit',
                'entryYear',
                'guardians',
                'enrollments' => fn ($q) => $q->where('status', 'active')
                    ->when($selectedYear, fn ($eq) => $eq->where('academic_year_id', $selectedYear->id))
                    ->with('classroom.homeroomTeacher'),
            ])
            ->paginate($request->integer('per_page', 20));

        // Totals pass over the whole filtered set, but with only the columns
        // pricing reads (school_unit_id + the enrollment's tingkat) - no
        // guardians or unit labels to hydrate.
        $totalsStudents = $filteredQuery()
            ->with(['enrollments' => fn ($q) => $q->select('id', 'student_id', 'classroom_id', 'status', 'academic_year_id')
                ->where('status', 'active')
                ->when($selectedYear, fn ($eq) => $eq->where('academic_year_id', $selectedYear->id))
                ->with('classroom:id,tingkat')])
            ->get(['id', 'school_unit_id']);

        // Preload all SPP rates for selected academic year
        $feeRates = $sppType && $selectedYear
            ? FeeRate::where('fee_type_id', $sppType->id)
                ->where('academic_year_id', $selectedYear->id)
                ->where('is_active', true)
                ->get()
            : collect();

        // Preload active student discounts (rows + totals in one query)
        $studentDiscounts = StudentDiscount::with('scheme.feeType')
            ->whereIn('student_id', $students->pluck('id')
                ->merge($totalsStudents->pluck('id'))
                ->unique()
                ->all())
            ->effectiveOn(now())
            ->get()
            ->groupBy('student_id');

        // Shared by the row mapper and the totals loop so both always agree
        // on what a student's SPP pricing is.
        $pricingFor = function (Student $student) use ($feeRates, $studentDiscounts, $sppType): array {
            $activeEnrollment = $student->enrollments->first();
            $classroom = $activeEnrollment?->classroom;
            $tingkat = $classroom?->tingkat;

            // Find matching SPP rate for student's unit and level
            $matchedRate = $feeRates
                ->where('school_unit_id', $student->school_unit_id)
                ->first(fn ($r) => $r->tingkat === $tingkat || $r->tingkat === null);

            $baseSpp = $matchedRate ? (float) $matchedRate->amount : 0.0;

            // Compute discounts
            $discounts = $studentDiscounts->get($student->id, collect());
            $discountTotal = 0.0;
            $discountDetails = [];

            foreach ($discounts as $sd) {
                if ($sppType && $sd->scheme && $sd->scheme->appliesTo($sppType, $student)) {
                    $cut = $sd->scheme->amountFor($baseSpp);
                    $discountTotal += $cut;
                    $discountDetails[] = [
                        'name' => $sd->scheme->name,
                        'type' => $sd->scheme->type,
                        'value' => (float) $sd->scheme->value,
                        'amount' => $cut,
                    ];
                }
            }

            return [
                'has_rate' => $matchedRate !== null,
                'base_spp' => $baseSpp,
                'discount_amount' => $discountTotal,
                'net_spp' => max(0.0, round($baseSpp - $discountTotal, 2)),
                'discounts' => $discountDetails,
            ];
        };

        $totals = ['base_spp' => 0.0, 'discount' => 0.0, 'net_spp' => 0.0];
        foreach ($totalsStudents as $student) {
            $pricing = $pricingFor($student);
            $totals['base_spp'] += $pricing['base_spp'];
            $totals['discount'] += $pricing['discount_amount'];
            $totals['net_spp'] += $pricing['net_spp'];
        }

        $data = $students->map(function (Student $student) use ($pricingFor) {
            $activeEnrollment = $student->enrollments->first();
            $classroom = $activeEnrollment?->classroom;

            $primaryGuardian = $student->guardians->first(fn ($g) => $g->pivot->is_primary)
                ?? $student->guardians->first();

            return [
                'ulid' => $student->ulid,
                'nis' => $student->nis,
                'nisn' => $student->nisn,
                'nama_lengkap' => $student->nama_lengkap,
                'nama_panggilan' => $student->nama_panggilan,
                'jenis_kelamin' => $student->jenis_kelamin,
                'status' => $student->status,
                'unit' => $student->schoolUnit ? [
                    'ulid' => $student->schoolUnit->ulid,
                    'code' => $student->schoolUnit->code,
                    'label' => $student->schoolUnit->label,
                    'jenjang' => strtoupper($student->schoolUnit->jenjang_group),
                ] : null,
                'classroom' => $classroom ? [
                    'ulid' => $classroom->ulid,
                    'name' => $classroom->name,
                    'tingkat' => $classroom->tingkat,
                    'wali_kelas' => $classroom->homeroomTeacher?->name,
                ] : null,
                'guardian' => $primaryGuardian ? [
                    'name' => $primaryGuardian->nama,
                    'relationship' => $primaryGuardian->pivot->relationship,
                    'phone' => $primaryGuardian->no_hp,
                ] : null,
                'pricing' => $pricingFor($student),
            ];
        });

        return response()->json([
            'students' => [
                'data' => $data,
                'meta' => [
                    'current_page' => $students->currentPage(),
                    'last_page' => $students->lastPage(),
                    'total' => $students->total(),
                    'per_page' => $students->perPage(),
                    // The KPI cards aggregate the whole filtered cohort, so
                    // they keep their meaning now that rows arrive 20 at a
                    // time instead of tracking whichever page is on screen.
                    'totals' => [
                        'base_spp' => round($totals['base_spp'], 2),
                        'discount' => round($totals['discount'], 2),
                        'net_spp' => round($totals['net_spp'], 2),
                    ],
                    'selected_academic_year' => $selectedYear?->year,
                    'selected_academic_year_ulid' => $selectedYear?->ulid,
                ],
            ],
        ]);
    }

    /**
     * A CSV laid out in Formulir Peserta Didik (F-PD) column order - the
     * official Dapodik data-collection form - not an import into Dapodik
     * itself, which has no public write API. Speeds up an operator's manual
     * re-entry rather than automating it away.
     */
    public function exportDapodik(Request $request, DapodikExportService $service): StreamedResponse
    {
        $unitCode = $request->string('unit')->value();

        $students = Student::query()
            ->visibleTo($request->user())
            ->active()
            ->when($unitCode, fn ($q) => $q->whereHas('schoolUnit', fn ($uq) => $uq->where('code', $unitCode)))
            ->with(['guardians', 'entryYear', 'enrollments', 'schoolUnit'])
            ->orderBy('nama_lengkap')
            ->get();

        $filename = 'dapodik_export_'.($unitCode ?: 'semua').'_'.now()->format('Y-m-d').'.csv';

        return response()->stream(function () use ($service, $students) {
            $handle = fopen('php://output', 'w');
            // Explicit separator/enclosure/escape: PHP 8.4 deprecates the
            // defaults, and Dapodik's parser expects the bytes unchanged -
            // same values the defaults produced (see ImportController's
            // CSV wrappers for the fuller note).
            fputcsv($handle, $service->headers(), ',', '"', '\\');

            foreach ($service->rows($students) as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Central-admin only (route gated, see routes/api.php) - a unit's own
     * TU/admin_unit can view and export their students but not edit or
     * remove them, same tier as user management and fee settings.
     */
    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('school_unit_ulid', $validated)) {
            $unit = SchoolUnit::where('ulid', $validated['school_unit_ulid'])->firstOrFail();

            // A unit move is not a label swap: while an enrollment is live,
            // changing the unit strands the student between two worlds - the
            // old class's rosters and sweeps still carry them, the new unit's
            // don't. The official lane for crossing units is promotion into
            // the next academic year (PromotionService), so refuse here and
            // point there rather than silently splitting the records.
            // Checked on the LATEST active enrollment, not just the current
            // academic year (audit T41): in the rollover-to-promotion window
            // the new year is active but old-year enrollments are still
            // 'active' too - a year-scoped query there found nothing and let
            // the exact split this guard exists for slip through.
            $movingUnits = $unit->id !== (int) $student->school_unit_id;

            $activeEnrollment = $movingUnits
                ? $student->enrollments()->where('status', 'active')->latest('id')->first()
                : null;

            if ($activeEnrollment) {
                $classroom = $activeEnrollment->classroom?->name;

                return response()->json([
                    'message' => "Siswa masih terdaftar aktif di kelas {$classroom} tahun ajaran berjalan. Pindah unit harus lewat alur kenaikan kelas antar tahun ajaran (menu Kenaikan Kelas), atau kosongkan kelasnya dulu.",
                ], 422);
            }

            // A same-day move splits today's official attendance across two
            // units (audit T65-a): the record already live in the old unit
            // stays, the new unit's sweep sees "no record yet" and writes a
            // second one - one day, two marks, and the rollup counts both.
            // The move waits for a day with nothing recorded yet.
            $hasLiveMarkToday = \App\Models\DailyRecord::query()
                ->active()
                ->where('student_id', $student->id)
                ->whereHas('dailySession', fn ($q) => $q->whereDate(
                    'date',
                    \Illuminate\Support\Carbon::now('Asia/Jakarta')->toDateString(),
                ))
                ->exists();

            if ($hasLiveMarkToday) {
                return response()->json([
                    'message' => 'Siswa sudah punya catatan presensi hari ini di unit lama. Pindahkan unit besok, atau batalkan (revoke) catatan presensinya hari ini dulu supaya tidak ada dua catatan di hari yang sama.',
                ], 422);
            }

            $student->school_unit_id = $unit->id;
        }

        $student->fill(collect($validated)->except(['school_unit_ulid'])->all());
        $student->save();

        ActivityLog::record($request->user(), 'student.updated', $student, collect($validated)->except(['nisn'])->all());

        return response()->json(['student' => $student->fresh('schoolUnit')]);
    }

    /**
     * A soft delete (Student uses SoftDeletes) - bills, payments, and
     * academic history a family or teacher already relies on stay intact
     * and merely stop surfacing this student as active, rather than being
     * torn out with them.
     */
    public function destroy(Request $request, Student $student): JsonResponse
    {
        ActivityLog::record($request->user(), 'student.deleted', $student, ['nama_lengkap' => $student->nama_lengkap]);

        $student->delete();

        return response()->json(['message' => 'Data siswa berhasil dihapus.']);
    }
}
