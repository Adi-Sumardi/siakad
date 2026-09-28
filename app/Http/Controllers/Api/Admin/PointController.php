<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PointRecord;
use App\Models\PointThreshold;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PointController extends Controller
{
    /**
     * Every student in scope with their running balance, so an admin can see
     * at a glance who is trending toward a bad band - not the raw ledger,
     * which is one student's own screen.
     *
     * Balances are computed in one grouped query rather than one SUM per
     * student - the difference between one query and three hundred on a
     * central admin's full roster.
     *
     * Paginated 20/page (bug batch Poin 5) and filterable by unit/jenjang
     * the same way the leaderboard is - the balance sort happens before
     * the slice, so page 1 is always the worst band first regardless of
     * how the filters shrink the roster.
     */
    public function index(Request $request): JsonResponse
    {
        $term = Term::current();

        if (! $term) {
            // Same {summary, students:{data, meta}} shape as the full path
            // (audit 2026-09-28): the old flat empty array crashed the
            // frontend's summary fallback between semesters.
            return response()->json([
                'term' => null,
                'summary' => ['total' => 0, 'flagged' => 0],
                'students' => ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 20, 'from' => null, 'to' => null]],
            ]);
        }

        $students = Student::query()
            ->visibleTo($request->user())
            ->active()
            ->when($search = $request->string('search')->value(), fn ($q) => $q->where(
                // Escaped, else a user typing "%" or "_" gets wildcard
                // semantics and the "search" widens instead of narrows.
                'nama_lengkap',
                'like',
                '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%',
            ))
            ->when($request->string('unit')->value(), fn ($q, $code) => $q->whereHas('schoolUnit', fn ($u) => $u->where('code', $code)))
            ->when($jenjang = $request->string('jenjang')->value(), function ($q, $jenjang) use ($term) {
                // Coarse keys stay unit-group-only - an unplaced student has
                // no rung to sit on and must NOT vanish from the discipline
                // roster just because a coarse filter was picked (mirrors
                // StudentController, which documents the same distinction).
                if (\App\Support\Jenjang::entry($jenjang) === null) {
                    $q->whereHas('schoolUnit', fn ($u) => $u->where('jenjang_group', \App\Support\Jenjang::groupOf($jenjang) ?? $jenjang));

                    return;
                }

                $q->whereHas('enrollments', function ($eq) use ($jenjang, $term) {
                    $eq->where('status', 'active')
                        ->where('academic_year_id', $term->academic_year_id)
                        ->whereHas('classroom', fn ($cq) => \App\Support\Jenjang::applyToClassroomQuery($cq, $jenjang));
                });
            })
            ->with('schoolUnit')
            ->orderBy('nama_lengkap')
            ->get();

        $balances = PointRecord::where('term_id', $term->id)
            ->active()
            ->whereIn('student_id', $students->pluck('id'))
            ->selectRaw('student_id, SUM(points) as balance')
            ->groupBy('student_id')
            ->pluck('balance', 'student_id');

        $rows = $students->map(function (Student $student) use ($balances) {
            $balance = (int) ($balances[$student->id] ?? 0);
            $threshold = PointThreshold::forBalance($balance, $student->school_unit_id);

            return [
                'student' => [
                    'ulid' => $student->ulid,
                    'nama_lengkap' => $student->nama_lengkap,
                    'unit' => $student->schoolUnit?->label,
                ],
                'balance' => $balance,
                'threshold' => $threshold ? ['ulid' => $threshold->ulid, 'label' => $threshold->label, 'color' => $threshold->color] : null,
            ];
        });

        // A student counts as "terkena ambang" only in a WARNING band - a
        // green "Aman" band must not flag the whole roster (audit
        // 2026-09-28).
        $isFlagged = fn (array $row) => $row['threshold'] !== null && $row['threshold']['color'] !== 'good';

        // The KPI cards count the unit/jenjang/search scope as a whole - so
        // the summary is taken BEFORE the threshold/flagged narrowing that
        // only shapes the paged list.
        $summary = [
            'total' => $rows->count(),
            'flagged' => $rows->filter($isFlagged)->count(),
        ];

        if ($thresholdUlid = $request->string('threshold')->value()) {
            $rows = $rows->filter(fn ($row) => $row['threshold']['ulid'] === $thresholdUlid)->values();
        }

        if ($request->boolean('flagged')) {
            $rows = $rows->filter($isFlagged)->values();
        }

        $sorted = $rows->sortBy('balance')->values();
        $perPage = 20;
        $page = max(1, $request->integer('page', 1));
        $pageRows = $sorted->forPage($page, $perPage)->values();

        // The {data, meta} shape the siswa tab's pagination already speaks
        // (a raw paginator would serialize flat), so the frontend component
        // is reused as-is. from/to come from the PAGE slice - beyond the
        // last page they are null, never "81-26 of 26".
        return response()->json([
            'term' => $term->label(),
            'summary' => $summary,
            'students' => [
                'data' => $pageRows,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($sorted->count() / $perPage)),
                    'total' => $sorted->count(),
                    'per_page' => $perPage,
                    'from' => $pageRows->isEmpty() ? null : ($page - 1) * $perPage + 1,
                    'to' => $pageRows->isEmpty() ? null : ($page - 1) * $perPage + $pageRows->count(),
                ],
            ],
        ]);
    }

    /**
     * The two top-5 boards (feature batch Poin 6): highest MERIT total and
     * highest VIOLATION total, from the same signed ledger the balance
     * reads - positive rows are merit, negative rows are violations, and
     * revoked rows are already excluded by active(). Only verified records
     * ever reach point_records in the first place (achievements write their
     * points at verify time), so "terverifikasi" is the ledger's own
     * contract.
     *
     * One pair of filters (unit + jenjang) drives both boards at once -
     * the school's explicit choice - and jenjang resolves through the
     * student's active enrollment's classroom, exactly like the student
     * list filter.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $term = Term::current();

        if (! $term) {
            return response()->json(['term' => null, 'merit' => [], 'violation' => []]);
        }

        $students = Student::query()
            ->visibleTo($request->user())
            ->active()
            ->when($request->string('unit')->value(), fn ($q, $code) => $q->whereHas('schoolUnit', fn ($u) => $u->where('code', $code)))
            ->when($jenjang = $request->string('jenjang')->value(), function ($q, $jenjang) use ($term) {
                $q->whereHas('enrollments', function ($eq) use ($jenjang, $term) {
                    $eq->where('status', 'active')
                        ->where('academic_year_id', $term->academic_year_id)
                        ->whereHas('classroom', fn ($cq) => \App\Support\Jenjang::applyToClassroomQuery($cq, $jenjang));
                });
            })
            ->with('schoolUnit')
            ->get();

        // One grouped query feeds both boards: the sign of the ledger row
        // decides which side it counts for.
        $totals = PointRecord::where('term_id', $term->id)
            ->active()
            ->whereIn('student_id', $students->pluck('id'))
            ->selectRaw('student_id, SUM(CASE WHEN points > 0 THEN points ELSE 0 END) as merit, SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) as violation')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $shape = fn ($students, $side) => $students
            ->map(fn (Student $student) => [
                'student' => [
                    'ulid' => $student->ulid,
                    'nama_lengkap' => $student->nama_lengkap,
                    'unit' => $student->schoolUnit?->label,
                ],
                'total' => (int) ($totals[$student->id]->{$side} ?? 0),
            ])
            ->filter(fn ($row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->take(5)
            ->values();

        return response()->json([
            'term' => $term->label(),
            'merit' => $shape($students, 'merit'),
            'violation' => $shape($students, 'violation'),
        ]);
    }
}
