<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttentionFilterRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Term;
use App\Services\Academic\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The drill-down behind the dashboard's watchlist numbers: the named
 * students the counts stand for, with the reason(s) each was flagged. Same
 * WatchlistService the summary tiles count from, so a tile's number and the
 * rows here can never disagree - the day they would is the day someone
 * bypasses the service.
 */
class AttentionController extends Controller
{
    public function index(AttentionFilterRequest $request, WatchlistService $watchlist): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Same period resolution as DashboardSummaryController::summary().
        $year = AcademicYear::current() ?? AcademicYear::latest('starts_on')->first();
        $term = $year?->activeTerm() ?? $year?->terms()->latest('starts_on')->first();
        $prevTerm = $watchlist->previousTerm($term);

        $students = Student::query()
            ->visibleTo($user)
            ->when($validated['unit'] ?? null, fn ($q, $unitCode) => $q
                ->whereHas('schoolUnit', fn ($uq) => $uq->where('code', $unitCode)))
            ->with('schoolUnit:id,ulid,code,label,jenjang_group')
            ->get(['id', 'ulid', 'nis', 'nama_lengkap', 'status', 'school_unit_id']);

        $enrollments = $year
            ? Enrollment::query()
                ->where('academic_year_id', $year->id)
                ->where('status', 'active')
                ->whereIn('student_id', $students->pluck('id'))
                ->with('classroom:id,ulid,name,tingkat')
                ->get(['id', 'student_id', 'classroom_id', 'absent_count'])
            : collect();

        $gradeTermIds = array_values(array_filter([$term?->id, $prevTerm?->id]));
        $grades = ! empty($gradeTermIds)
            ? Grade::query()->visibleTo($user)->whereIn('term_id', $gradeTermIds)
                ->get(['student_id', 'subject_id', 'term_id', 'category', 'score'])
            : collect();

        $points = $term
            ? collect(DB::table('point_records')
                ->where('term_id', $term->id)
                ->where('status', 'recorded')
                ->whereIn('student_id', $students->pluck('id'))
                ->get(['student_id', 'points']))
            : collect();

        $watch = $watchlist->identify($students, $enrollments, $grades, $points, $term, $prevTerm);
        $studentById = $students->keyBy('id');
        $classroomByStudent = $enrollments->groupBy('student_id')->map(fn ($rows) => $rows->first()->classroom);

        $rows = $watch
            ->when($validated['reason'] ?? null, fn (Collection $rows, string $reason) => $rows
                ->filter(fn (array $row) => in_array($reason, $row['reasons'], true)))
            ->map(function (array $row) use ($studentById, $classroomByStudent) {
                $student = $studentById->get($row['student_id']);
                $classroom = $classroomByStudent->get($row['student_id']);

                return [
                    'ulid' => $student->ulid,
                    'nama_lengkap' => $student->nama_lengkap,
                    'nis' => $student->nis,
                    'status' => $student->status,
                    'unit' => $student->schoolUnit ? [
                        'code' => $student->schoolUnit->code,
                        'label' => $student->schoolUnit->label,
                        'jenjang' => strtoupper($student->schoolUnit->jenjang_group),
                    ] : null,
                    'classroom' => $classroom ? ['ulid' => $classroom->ulid, 'name' => $classroom->name] : null,
                    'reasons' => $row['reasons'],
                    'metrics' => [
                        'alpa_count' => $row['alpa_count'],
                        'current_average' => $row['current_average'],
                        'previous_average' => $row['previous_average'],
                        'average_drop' => $row['average_drop'],
                        'violation_records' => $row['violation_records'],
                    ],
                ];
            })
            // Most conditions first - the student the staff should call
            // before anyone else - then alphabetical so the page is stable
            // between visits.
            ->sort(fn (array $a, array $b) => count($b['reasons']) <=> count($a['reasons'])
                ?: strcmp($a['nama_lengkap'], $b['nama_lengkap']))
            ->values();

        $counts = collect(WatchlistService::REASONS)
            ->mapWithKeys(fn (string $reason) => [
                $reason => $watch->filter(fn (array $row) => in_array($reason, $row['reasons'], true))->count(),
            ]);

        return response()->json([
            'period' => [
                'academic_year' => $year?->year,
                'term' => $term?->name,
                'term_label' => $term ? ucfirst($term->name).' '.$year?->year : null,
            ],
            'thresholds' => [
                'kkm' => WatchlistService::KKM,
                'min_alpa' => WatchlistService::HIGH_ABSENTEEISM_ALPA,
                'grade_drop' => WatchlistService::GRADE_DROP_POINTS,
            ],
            'total' => $watch->count(),
            'counts' => $counts,
            'students' => $rows,
        ]);
    }
}
