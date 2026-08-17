<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\BillingRun;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Issuing a cohort's bills, always preview first.
 *
 * The preview is a real step, not a courtesy: a wrong rate applied to 300
 * students is 300 phone calls, and it is only visible before the fact.
 */
class BillingRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $runs = BillingRun::query()
            ->with(['feeType'])
            // A per-unit admin sees their own unit's runs plus the school-wide
            // ones that produced their students' bills.
            ->when($request->user()->isUnitScoped(), fn ($q) => $q
                ->where(fn ($w) => $w->where('school_unit_id', $request->user()->school_unit_id)
                    ->orWhereNull('school_unit_id')))
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'runs' => $runs->map(fn (BillingRun $run) => [
                'ulid' => $run->ulid,
                'fee_type' => $run->feeType->name,
                'period_month' => $run->period_month,
                'status' => $run->status,
                'bills_created' => $run->bills_created,
                'bills_skipped' => $run->bills_skipped,
                'total_amount' => (float) $run->total_amount,
                'finished_at' => $run->finished_at,
            ]),
        ]);
    }

    public function preview(Request $request, BillGenerator $generator): JsonResponse
    {
        [$type, $year, $unit, $month, $due] = $this->resolve($request);

        $preview = $generator->preview($type, $year, $unit, $month, $year->activeTerm(), $due);

        return response()->json([
            'fee_type' => $type->name,
            'unit' => $unit?->label ?? 'Semua unit',
            'period_month' => $month,
            'eligible' => $preview['eligible'],
            'total_amount' => $preview['total_amount'],
            'discount_amount' => $preview['discount_amount'],
            'skipped' => $preview['skipped'],
        ]);
    }

    public function store(Request $request, BillGenerator $generator): JsonResponse
    {
        [$type, $year, $unit, $month, $due] = $this->resolve($request);

        $run = $generator->run($type, $year, $unit, $month, $year->activeTerm(), $due, $request->user());

        ActivityLog::record($request->user(), 'billing_run.executed', $run, [
            'fee_type' => $type->code,
            'unit' => $unit?->code,
            'month' => $month,
            'bills_created' => $run->bills_created,
            'total_amount' => (float) $run->total_amount,
        ]);

        return response()->json([
            'run' => [
                'ulid' => $run->ulid,
                'status' => $run->status,
                'bills_created' => $run->bills_created,
                'bills_skipped' => $run->bills_skipped,
                'total_amount' => (float) $run->total_amount,
                'skipped' => $run->skipped_detail ?? [],
            ],
        ], 201);
    }

    /**
     * @return array{0: FeeType, 1: AcademicYear, 2: ?SchoolUnit, 3: ?int, 4: ?Carbon}
     */
    private function resolve(Request $request): array
    {
        $validated = $request->validate([
            'fee_type_code' => 'required|exists:fee_types,code',
            'month' => 'nullable|integer|min:1|max:12',
            'unit_code' => 'nullable|exists:school_units,code',
            'due_date' => 'nullable|date',
        ]);

        $type = FeeType::where('code', $validated['fee_type_code'])->firstOrFail();
        $year = AcademicYear::current();

        abort_if(! $year, 422, 'Belum ada tahun ajaran aktif.');

        /** @var User $user */
        $user = $request->user();

        // A per-unit admin may only ever run their own unit, whatever the
        // request asked for. Ignoring the parameter is safer than validating it:
        // there is no combination of inputs that widens their reach.
        $unit = $user->isUnitScoped()
            ? $user->schoolUnit
            : (isset($validated['unit_code']) ? SchoolUnit::findByCode($validated['unit_code']) : null);

        abort_if($user->isUnitScoped() && ! $unit, 422, 'Akun admin unit ini belum terhubung ke unit mana pun.');

        return [
            $type,
            $year,
            $unit,
            $validated['month'] ?? null,
            isset($validated['due_date']) ? Carbon::parse($validated['due_date']) : null,
        ];
    }
}
