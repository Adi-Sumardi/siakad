<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
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
                ->map(fn (Holiday $h) => [
                    'ulid' => $h->ulid,
                    'date' => $h->date->toDateString(),
                    'label' => $h->label,
                ]),
        ]);
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::create($request->validated());

        return response()->json([
            'holiday' => [
                'ulid' => $holiday->ulid,
                'date' => $holiday->date->toDateString(),
                'label' => $holiday->label,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $holiday = Holiday::where('ulid', $ulid)->firstOrFail();
        $holiday->delete();

        return response()->json(['message' => 'Hari libur dihapus.']);
    }
}
