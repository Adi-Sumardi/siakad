<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceReportController extends Controller
{
    /** H/S/I/A tallies over a date range, grouped by class and by subject - same shape as ReportController::collections(). */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();

        $records = AttendanceRecord::query()
            ->visibleTo($request->user())
            ->active()
            // occurred_on is stored with a time component even though it's
            // conceptually date-only (Eloquent's `date` cast writes a full
            // datetime string) - bound the range with real timestamps, not
            // date-only strings, or a record on the last day of the range
            // would sort after it and get silently excluded.
            ->whereBetween('occurred_on', [$from, $to])
            ->with(['classroom', 'attendanceSession.classSchedule.subject'])
            ->get();

        $tally = fn ($group) => [
            'hadir' => $group->where('attendance_status', 'hadir')->count(),
            'sakit' => $group->where('attendance_status', 'sakit')->count(),
            'izin' => $group->where('attendance_status', 'izin')->count(),
            'alpa' => $group->where('attendance_status', 'alpa')->count(),
        ];

        $byClass = $records
            ->groupBy(fn (AttendanceRecord $r) => $r->classroom->name ?? 'Tanpa kelas')
            ->map(fn ($group, $kelas) => ['kelas' => $kelas, ...$tally($group)])
            ->sortByDesc('alpa')
            ->values();

        $bySubject = $records
            ->groupBy(fn (AttendanceRecord $r) => $r->attendanceSession?->classSchedule?->subject?->name ?? 'Tanpa mata pelajaran')
            ->map(fn ($group, $mapel) => ['mata_pelajaran' => $mapel, ...$tally($group)])
            ->sortByDesc('alpa')
            ->values();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => ['total_records' => $records->count(), ...$tally($records)],
            'by_class' => $byClass,
            'by_subject' => $bySubject,
        ]);
    }
}
