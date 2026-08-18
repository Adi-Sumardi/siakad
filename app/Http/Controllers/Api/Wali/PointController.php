<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointRecordResource;
use App\Models\PointThreshold;
use App\Models\Student;
use App\Models\Term;
use App\Services\Points\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PointController extends Controller
{
    /**
     * The ledger for one child, plus where their balance sits against the
     * bands that apply to their unit - a bare number means nothing without
     * knowing whether -30 is "Peringatan 1" or well short of it.
     */
    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $studentInfo = ['ulid' => $student->ulid, 'nama_lengkap' => $student->nama_lengkap, 'nama_panggilan' => $student->nama_panggilan];
        $term = Term::current();

        if (! $term) {
            return response()->json(['student' => $studentInfo, 'balance' => 0, 'term' => null, 'threshold' => null, 'records' => []]);
        }

        $balance = app(PointLedger::class)->balance($student, $term);
        $threshold = PointThreshold::forBalance($balance, $student->school_unit_id);

        $records = $student->pointRecords()
            ->where('term_id', $term->id)
            ->with(['pointRule', 'recordedBy'])
            ->orderByDesc('occurred_on')
            ->get();

        return response()->json([
            'student' => $studentInfo,
            'balance' => $balance,
            'term' => $term->label(),
            'threshold' => $threshold ? [
                'label' => $threshold->label,
                'color' => $threshold->color,
                'action' => $threshold->action,
            ] : null,
            'records' => PointRecordResource::collection($records),
        ]);
    }
}
