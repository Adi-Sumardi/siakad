<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyRecordResource;
use App\Models\Student;
use App\Models\Term;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guardian's attendance view reads the DAILY layer (§8): "was my child
 * at school today", in days, with the gate check-in time when there was one.
 * Per-lesson detail remains a teacher-side view - a parent's question is
 * never "which period did they skip".
 */
class AttendanceController extends Controller
{
    public function index(Request $request, string $ulid, DailyAttendanceService $daily): JsonResponse
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

        $records = $student->dailyRecords()
            ->where('term_id', $term->id)
            ->active()
            ->with('dailySession')
            ->orderByDesc('date')
            ->orderByDesc('checked_in_at')
            ->get();

        return response()->json([
            'student' => $studentInfo,
            'term' => $term->label(),
            'summary' => $daily->summary($student, $term),
            'records' => DailyRecordResource::collection($records),
        ]);
    }
}
