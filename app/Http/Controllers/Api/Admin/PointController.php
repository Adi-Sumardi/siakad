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
}
