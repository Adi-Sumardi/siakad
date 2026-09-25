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
     */
    public function index(Request $request): JsonResponse
    {
        $term = Term::current();

        if (! $term) {
            return response()->json(['students' => [], 'term' => null]);
        }

        $students = Student::query()
            ->visibleTo($request->user())
            ->active()
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

        if ($thresholdUlid = $request->string('threshold')->value()) {
            $rows = $rows->filter(fn ($row) => $row['threshold']['ulid'] === $thresholdUlid)->values();
        }

        return response()->json([
            'term' => $term->label(),
            'students' => $rows->sortBy('balance')->values(),
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
