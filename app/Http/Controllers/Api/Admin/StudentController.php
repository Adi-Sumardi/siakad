<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /**
     * List all students per unit, per jenjang, with SPP rates and active discounts.
     */
    public function index(Request $request): JsonResponse
    {
        $activeYear = AcademicYear::where('is_active', true)->first()
            ?? AcademicYear::latest('starts_on')->first();

        $sppType = FeeType::where('code', 'spp')->first();

        $studentsQuery = Student::query()
            ->visibleTo($request->user())
            ->with([
                'schoolUnit',
                'entryYear',
                'guardians',
                'enrollments' => fn ($q) => $q->where('status', 'active')->with('classroom.homeroomTeacher'),
            ])
            ->when($request->string('search')->value(), function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('nama_panggilan', 'like', "%{$search}%")
                        ->orWhere('nis', 'like', "%{$search}%")
                        ->orWhere('no_pendaftaran', 'like', "%{$search}%");
                });
            })
            ->when($request->string('unit')->value(), fn ($q, $unitCode) => $q->whereHas('schoolUnit', fn ($uq) => $uq->where('code', $unitCode)))
            ->when($request->string('jenjang')->value(), fn ($q, $jenjang) => $q->whereHas('schoolUnit', fn ($uq) => $uq->where('jenjang_group', $jenjang)))
            ->when($request->string('status')->value(), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('nama_lengkap');

        $students = $studentsQuery->paginate($request->integer('per_page', 25));

        // Preload all SPP rates for quick lookup
        $feeRates = $sppType && $activeYear
            ? FeeRate::where('fee_type_id', $sppType->id)
                ->where('academic_year_id', $activeYear->id)
                ->where('is_active', true)
                ->get()
            : collect();

        // Preload active student discounts
        $studentIds = $students->pluck('id')->all();
        $studentDiscounts = StudentDiscount::with('scheme.feeType')
            ->whereIn('student_id', $studentIds)
            ->effectiveOn(now())
            ->get()
            ->groupBy('student_id');

        $data = $students->map(function (Student $student) use ($feeRates, $studentDiscounts, $sppType) {
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

            $netSpp = max(0.0, round($baseSpp - $discountTotal, 2));

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
                    'name' => $primaryGuardian->nama_lengkap,
                    'relationship' => $primaryGuardian->pivot->relationship,
                    'phone' => $primaryGuardian->phone,
                ] : null,
                'pricing' => [
                    'has_rate' => $matchedRate !== null,
                    'base_spp' => $baseSpp,
                    'discount_amount' => $discountTotal,
                    'net_spp' => $netSpp,
                    'discounts' => $discountDetails,
                ],
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
                    'active_academic_year' => $activeYear?->year,
                ],
            ],
        ]);
    }
}
