<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFeeRateRequest;
use App\Http\Requests\Admin\StoreFeeTypeRequest;
use App\Http\Requests\Admin\UpdateFeeRateRequest;
use App\Http\Requests\Admin\UpdateFeeTypeRequest;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\FeeComponent;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Services\Billing\BillingApiClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The fee catalogue and its rates.
 *
 * Reading is open to both admin kinds - a per-unit admin has to know what fee
 * types and rates exist before they can run billing for their own unit.
 * *Writing* is central-admin only: rates decide what hundreds of families are
 * charged, so the blast radius of a typo is much larger than the screen it was
 * typed on. rates() scopes reads to the caller's own unit. The one write
 * exception is UNIT_MANAGED_FEE_CODE below: its nominal is each unit's own
 * decision, so a per-unit admin may store/update that rate for their unit -
 * enforced here (type check + unit forced from their account), while the
 * route itself lives in the shared group of routes/api.php.
 */
class FeeSettingController extends Controller
{
    /**
     * The single fee type a per-unit admin may price themselves (school
     * decision 2026-09-22: each unit's Cambridge nominal differs). Every
     * other rate stays a foundation-level decision.
     */
    public const UNIT_MANAGED_FEE_CODE = 'cambridge';

    public function types(): JsonResponse
    {
        // Category names only - no pricing here, so there is nothing a
        // per-unit admin should not see.
        return response()->json([
            'fee_types' => FeeType::orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (FeeType $type) => [
                    'ulid' => $type->ulid,
                    'code' => $type->code,
                    'name' => $type->name,
                    'recurrence' => $type->recurrence,
                    'allow_installment' => $type->allow_installment,
                    'requires_selection' => $type->requires_selection,
                    'requires_roster_membership' => $type->requires_roster_membership,
                    'is_active' => $type->is_active,
                    'rate_count' => $type->rates()->count(),
                    // Whether e-SPP has a VA prefix for this fee type - a
                    // manual bill of a type without one is payable at the
                    // front desk only, and the UI must say so up front.
                    'has_va_prefix' => BillingApiClient::resolvePrefix($type->code) !== null,
                ]),
        ]);
    }

    public function storeType(StoreFeeTypeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $type = FeeType::create($validated);

        ActivityLog::record($request->user(), 'fee_type.created', $type, ['code' => $type->code]);

        return response()->json(['fee_type' => $type], 201);
    }

    public function updateType(UpdateFeeTypeRequest $request, FeeType $feeType): JsonResponse
    {
        $validated = $request->validated();

        $feeType->update($validated);

        ActivityLog::record($request->user(), 'fee_type.updated', $feeType, $validated);

        return response()->json(['fee_type' => $feeType]);
    }

    /** bills.fee_type_id is ON DELETE RESTRICT - once anything has ever been billed under this type, the database itself refuses. */
    public function destroyType(Request $request, FeeType $feeType): JsonResponse
    {
        try {
            $feeType->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true)) {
                return response()->json([
                    'message' => "Jenis biaya \"{$feeType->name}\" sudah pernah ditagihkan ke siswa - tidak bisa dihapus, nonaktifkan saja.",
                ], 422);
            }

            throw $e;
        }

        ActivityLog::record($request->user(), 'fee_type.deleted', $feeType, ['code' => $feeType->code, 'name' => $feeType->name]);

        return response()->json(['message' => 'Jenis biaya berhasil dihapus.']);
    }

    public function rates(Request $request): JsonResponse
    {
        $rates = FeeRate::query()
            ->with(['feeType', 'schoolUnit', 'academicYear', 'components'])
            // A per-unit admin only ever sees their own unit's rates, whatever
            // ?unit= asked for - the same "forced, not trusted" rule
            // BillingRunController applies to which unit a run actually bills.
            ->when($request->user()->isUnitScoped(), fn ($q) => $q->where('school_unit_id', $request->user()->school_unit_id))
            ->when(! $request->user()->isUnitScoped() && $request->string('unit')->value(), fn ($q, $code) => $q->whereHas('schoolUnit', fn ($u) => $u->where('code', $code)))
            ->when($request->string('type')->value(), fn ($q, $code) => $q->whereHas('feeType', fn ($t) => $t->where('code', $code)))
            ->when($request->string('year')->value(), fn ($q, $year) => $q->whereHas('academicYear', fn ($y) => $y->where('year', $year)))
            ->get();

        return response()->json([
            'rates' => $rates->map(fn (FeeRate $rate) => [
                'ulid' => $rate->ulid,
                'fee_type' => ['code' => $rate->feeType->code, 'name' => $rate->feeType->name],
                'unit' => ['code' => $rate->schoolUnit->code, 'label' => $rate->schoolUnit->label],
                'academic_year' => $rate->academicYear->year,
                'tingkat' => $rate->tingkat,
                'amount' => (float) $rate->amount,
                'due_day' => $rate->due_day,
                'late_fee_amount' => (float) $rate->late_fee_amount,
                'late_fee_grace_days' => $rate->late_fee_grace_days,
                'is_active' => $rate->is_active,
                'components' => $rate->components->map(fn (FeeComponent $c) => [
                    'ulid' => $c->ulid,
                    'name' => $c->name,
                    'amount' => (float) $c->amount,
                    'default_qty' => $c->default_qty,
                    'is_optional' => $c->is_optional,
                    'has_size_option' => $c->has_size_option,
                    'size_options' => $c->size_options,
                ]),
            ]),
        ]);
    }

    public function storeRate(StoreFeeRateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $type = FeeType::where('ulid', $validated['fee_type_ulid'])->firstOrFail();
        $unit = SchoolUnit::where('ulid', $validated['school_unit_ulid'])->firstOrFail();
        $year = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        // The one write a per-unit admin may make: their own Cambridge rate.
        // Everything else - and any unit other than their own - is refused,
        // and the unit is forced from the account rather than trusted from
        // the request, the same line BillingRunController draws.
        if ($request->user()->isUnitScoped()) {
            abort_unless($type->code === self::UNIT_MANAGED_FEE_CODE, 403, 'Jenis biaya ini hanya bisa diatur admin pusat. Admin unit hanya mengelola tarif Cambridge untuk unitnya sendiri.');
            abort_if(! $request->user()->schoolUnit, 422, 'Akun admin unit ini tidak terikat ke unit sekolah mana pun.');

            $unit = $request->user()->schoolUnit;

            // A unit without a registered VA prefix (TK/RA/PG/SMA for
            // Cambridge) could never pay the resulting bills - refuse early
            // instead of letting a dead rate onto the catalogue.
            abort_if(BillingApiClient::resolvePrefix(self::UNIT_MANAGED_FEE_CODE, $unit) === null, 403, "Unit {$unit->label} tidak mengikuti program Cambridge.");
        }

        // The unique index would catch this anyway, but a 422 naming the clash
        // is a better answer than a 500 from a constraint violation.
        $clash = FeeRate::where('fee_type_id', $type->id)
            ->where('school_unit_id', $unit->id)
            ->where('academic_year_id', $year->id)
            ->where('tingkat', $validated['tingkat'] ?? null)
            ->exists();

        if ($clash) {
            return response()->json([
                'message' => 'Tarif untuk kombinasi jenis biaya, unit, tingkat, dan tahun ajaran ini sudah ada.',
            ], 422);
        }

        $rate = DB::transaction(function () use ($validated, $type, $unit, $year) {
            $rate = FeeRate::create([
                'fee_type_id' => $type->id,
                'school_unit_id' => $unit->id,
                'academic_year_id' => $year->id,
                'tingkat' => $validated['tingkat'] ?? null,
                'amount' => $validated['amount'],
                'due_day' => $validated['due_day'] ?? null,
                'late_fee_amount' => $validated['late_fee_amount'] ?? 0,
                'late_fee_grace_days' => $validated['late_fee_grace_days'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['components'] ?? [] as $i => $component) {
                FeeComponent::create($component + ['fee_rate_id' => $rate->id, 'sort_order' => $i]);
            }

            return $rate;
        });

        ActivityLog::record($request->user(), 'fee_rate.created', $rate, [
            'type' => $type->code,
            'unit' => $unit->code,
            'amount' => $validated['amount'],
        ]);

        return response()->json(['rate' => $rate->load('components')], 201);
    }

    public function updateRate(UpdateFeeRateRequest $request, FeeRate $feeRate): JsonResponse
    {
        // Same line as storeRate: a per-unit admin touches only the
        // Cambridge rate of their own unit.
        if ($request->user()->isUnitScoped()) {
            abort_unless(
                $feeRate->feeType->code === self::UNIT_MANAGED_FEE_CODE
                && $feeRate->school_unit_id === $request->user()->school_unit_id,
                403,
                'Admin unit hanya bisa mengubah tarif Cambridge untuk unitnya sendiri.'
            );
        }

        $validated = $request->validated();

        $before = (float) $feeRate->amount;
        $feeRate->update($validated);

        // Bills already issued keep the amount they were issued at - they hold
        // their own copy, and fee_rate_id is an audit trail, not a live lookup.
        ActivityLog::record($request->user(), 'fee_rate.updated', $feeRate, [
            'amount_before' => $before,
            'amount_after' => (float) $feeRate->amount,
        ]);

        return response()->json(['rate' => $feeRate->fresh('components')]);
    }

    /**
     * bills.fee_rate_id is ON DELETE SET NULL, not RESTRICT - a bill already
     * issued keeps its own copy of the amount (see updateRate's comment), so
     * deleting the rate it was issued under never needs blocking.
     */
    public function destroyRate(Request $request, FeeRate $feeRate): JsonResponse
    {
        ActivityLog::record($request->user(), 'fee_rate.deleted', $feeRate, [
            'type' => $feeRate->feeType->code,
            'unit' => $feeRate->schoolUnit->code,
            'amount' => (float) $feeRate->amount,
        ]);

        $feeRate->delete();

        return response()->json(['message' => 'Tarif berhasil dihapus.']);
    }
}
