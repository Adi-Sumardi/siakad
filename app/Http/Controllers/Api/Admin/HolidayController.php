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

        // A soft warning, not a refusal (audit §6a-3): a holiday on a
        // weekday no enabled unit operates on is harmless by design -
        // index()'s hint already says the calendar side of it - but it is
        // almost always a typo'd date, so the server says so out loud too.
        // Only when settings exist at all: before any unit has configured
        // its days there is nothing to compare against, and holiday data
        // may legitimately be prepared first.
        $activeDays = $this->activeDays();

        $warning = ($activeDays !== [] && ! in_array((int) $holiday->date->dayOfWeekIso, $activeDays, true))
            ? 'Catatan: tidak ada unit yang menjalankan presensi di hari '
                .$holiday->date->locale('id')->translatedFormat('l')
                .' - libur ini tidak berdampak apa-apa. Periksa kembali tanggalnya.'
            : null;

        return response()->json(
            ['holiday' => $this->row($holiday)] + ($warning !== null ? ['warning' => $warning] : []),
            201,
        );
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
