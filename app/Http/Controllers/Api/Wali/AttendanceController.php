<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Student;
use App\Models\Term;
use App\Services\Attendance\AttendanceLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request, string $ulid, AttendanceLedger $ledger): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $studentInfo = ['ulid' => $student->ulid, 'nama_lengkap' => $student->nama_lengkap];
        $term = Term::current();

        if (! $term) {
            return response()->json([
                'student' => $studentInfo, 'term' => null,
                'summary' => ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0], 'records' => [],
            ]);
        }

        $records = $student->attendanceRecords()
            ->where('term_id', $term->id)
            ->active()
            ->orderByDesc('occurred_on')
            ->get();

        return response()->json([
            'student' => $studentInfo,
            'term' => $term->label(),
            'summary' => $ledger->summary($student, $term),
            'records' => AttendanceRecordResource::collection($records),
        ]);
    }
}
