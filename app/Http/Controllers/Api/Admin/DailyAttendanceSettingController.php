<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDailyAttendanceSettingRequest;
use App\Models\SchoolUnit;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The per-unit configuration screen for the daily attendance layer. A
 * per-unit admin is always editing their own unit's row - the BillingRun
 * line: the unit is forced from the account, never trusted from the request.
 * A central admin names the unit explicitly (?unit=<ulid>).
 */
class DailyAttendanceSettingController extends Controller
{
    public function index(Request $request, DailyAttendanceService $service): JsonResponse
    {
        $setting = $service->ensureSettings($this->resolveUnit($request));

        return response()->json($this->present($setting));
    }

    public function update(UpdateDailyAttendanceSettingRequest $request, DailyAttendanceService $service): JsonResponse
    {
        $setting = $service->ensureSettings($this->resolveUnit($request));
        $setting->update($request->safe()->except(['unit']));

        // A bells edit must be visible on the public link and the boards the
        // moment it is saved, not at the next 5-minute sweep tick: create
        // today's windows if the unit just switched today on, then carry the
        // edit over to today's already-open/already-closed sessions (reopen
        // included - see resyncTodayWindows).
        $service->ensureSessionsForDate($setting->fresh());
        $service->resyncTodayWindows($setting->fresh(), $request->user());

        return response()->json($this->present($setting->fresh()));
    }

    /**
     * Issues the unit's public check-in link (no-op when one already exists)
     * - the link that gets pasted into the WA class groups' description
     * (§5D). One link per unit: students are separated by NIS, never by URL.
     */
    public function issuePublicLink(Request $request, DailyAttendanceService $service): JsonResponse
    {
        $setting = $service->ensureSettings($this->resolveUnit($request));

        if (! $setting->public_slug) {
            $setting->forceFill(['public_slug' => Str::random(16)])->save();
        }

        return response()->json($this->present($setting->fresh()));
    }

    /** Rotates the slug - the "bocor" button. Any link still printed in an old group description dies on the spot. */
    public function resetPublicLink(Request $request, DailyAttendanceService $service): JsonResponse
    {
        $setting = $service->ensureSettings($this->resolveUnit($request));
        $setting->forceFill(['public_slug' => Str::random(16)])->save();

        return response()->json($this->present($setting->fresh()));
    }

    private function resolveUnit(Request $request): SchoolUnit
    {
        if ($request->user()->isUnitScoped()) {
            // Fail toward "not found" when an admin_unit has no unit bound -
            // the safe direction visibleTo() established.
            return $request->user()->schoolUnit ?: abort(404);
        }

        return SchoolUnit::where('ulid', $request->input('unit'))->firstOrFail();
    }

    private function present($setting): array
    {
        return [
            'unit' => ['ulid' => $setting->schoolUnit->ulid, 'label' => $setting->schoolUnit->label],
            'enabled' => $setting->enabled,
            'days' => $setting->days,
            'masuk' => [
                'opens_at' => $setting->masuk_opens_at,
                'closes_at' => $setting->masuk_closes_at,
                'late_after' => $setting->masuk_late_after,
            ],
            'pulang' => [
                'enabled' => $setting->pulang_enabled,
                'opens_at' => $setting->pulang_opens_at,
                'closes_at' => $setting->pulang_closes_at,
            ],
            'intake_mode' => $setting->intake_mode,
            'geo' => [
                'required' => $setting->geo_required,
                'gate_lat' => $setting->gate_lat,
                'gate_lng' => $setting->gate_lng,
                'radius_m' => $setting->geo_radius_m,
            ],
            'qr_required' => $setting->qr_required,
            'notifications' => [
                'masuk' => $setting->notify_masuk,
                'pulang' => $setting->notify_pulang,
                'absent' => $setting->notify_absent,
            ],
            // Null until the unit issues one - the frontend only offers the
            // copy/QR buttons for a slug that exists.
            'public_slug' => $setting->public_slug,
            'public_path' => $setting->public_slug ? '/absen/'.$setting->public_slug : null,
        ];
    }
}
