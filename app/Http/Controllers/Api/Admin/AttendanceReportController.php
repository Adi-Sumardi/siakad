<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeRequest;
use App\Models\DailyRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceReportController extends Controller
{
    /**
     * H/S/I/A tallies in DAYS over a date range, grouped by class and by
     * unit - same shape as ReportController::collections(). The daily layer
     * is the official source (§8), so these are the same numbers the rapor
     * and the watchlist quote; per-lesson detail stays on the teacher's
     * session screens and has no place in a cross-unit report. Masuk windows
     * only - a pulang row is the same day told twice.
     */
    public function summary(DateRangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Jakarta wall-clock defaults (audit T45): the report's month window
        // is a school-calendar question, not a server-timezone one.
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : Carbon::now('Asia/Jakarta')->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::now('Asia/Jakarta')->endOfDay();

        $records = DailyRecord::query()
            ->visibleTo($request->user())
            ->active()
            ->whereHas('dailySession', fn ($q) => $q->where('type', 'masuk'))
            // date may store a midnight time component depending on the
            // driver (the occurred_on lesson the per-lesson report learned);
            // bound with full start/end-of-day timestamps, never bare date
            // strings, or the range's last day falls out.
            ->whereBetween('date', [$from, $to])
            ->with(['classroom', 'dailySession.schoolUnit'])
            ->get();

        $tally = fn ($group) => [
            'hadir' => $group->where('attendance_status', 'hadir')->count(),
            'sakit' => $group->where('attendance_status', 'sakit')->count(),
            'izin' => $group->where('attendance_status', 'izin')->count(),
            'alpa' => $group->where('attendance_status', 'alpa')->count(),
        ];

        $byClass = $records
            // Unit-scoped key (audit T46-e): "1A" exists in more than one
            // unit, and a name-only group merged them (plus every unplaced
            // student into one shared 'Tanpa kelas' bucket).
            ->groupBy(fn (DailyRecord $r) => ($r->dailySession?->schoolUnit?->label ?? 'Tanpa unit')
                .' · '
                .($r->classroom?->name ?? 'Tanpa kelas'))
            ->map(fn ($group, $kelas) => ['kelas' => $kelas, ...$tally($group)])
            ->sortByDesc('alpa')
            ->values();

        $byUnit = $records
            ->groupBy(fn (DailyRecord $r) => $r->dailySession?->schoolUnit?->label ?? 'Tanpa unit')
            ->map(fn ($group, $unit) => ['unit' => $unit, ...$tally($group)])
            ->sortByDesc('alpa')
            ->values();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => ['total_records' => $records->count(), ...$tally($records)],
            'by_class' => $byClass,
            'by_unit' => $byUnit,
        ]);
    }
}
