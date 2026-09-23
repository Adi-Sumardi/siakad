<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Models\DailyAttendanceSetting;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * School-wide holiday calendar. On a listed date no daily attendance
 * sessions open, so a national holiday falling on a school day can never
 * sweep the whole school into alpa at 08:00. Central admin only: holidays
 * are shared across every unit.
 */
class HolidayController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'holidays' => Holiday::query()
                ->orderBy('date')
                ->get()
                ->map(fn (Holiday $h) => $this->row($h)),
            // The weekdays at least one enabled unit actually operates on -
            // lets the calendar mark a holiday that lands on a day no unit
            // runs anyway (harmless noise: runsOn() already gates sessions,
            // and units may change their days later, so it stays a hint).
            'active_days' => $this->activeDays(),
        ]);
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::create($request->validated());

        return response()->json(['holiday' => $this->row($holiday)], 201);
    }

    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $holiday = Holiday::where('ulid', $ulid)->firstOrFail();
        $holiday->delete();

        return response()->json(['message' => 'Hari libur dihapus.']);
    }

    /** @return array{ulid: string, date: string, weekday: string, label: string} */
    private function row(Holiday $h): array
    {
        return [
            'ulid' => $h->ulid,
            'date' => $h->date->toDateString(),
            'weekday' => $h->date->locale('id')->translatedFormat('l'),
            'label' => $h->label,
        ];
    }

    /** @return list<int> */
    private function activeDays(): array
    {
        $days = DailyAttendanceSetting::query()
            ->where('enabled', true)
            ->get(['days'])
            ->flatMap(fn ($s) => $s->days ?? [])
            ->unique()
            ->values()
            ->all();

        sort($days);

        return $days;
    }
}
