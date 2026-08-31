<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\StudentFeeSelection;
use App\Services\Billing\FeeSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Where a family picks sizes/items for a fee type flagged requires_selection
 * (seragam, in the dev seed) before BillGenerator will charge them at all -
 * see BillGenerator::evaluate() for the other half of this.
 */
class FeeSelectionController extends Controller
{
    public function index(Request $request, string $studentUlid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();
        $year = AcademicYear::current();
        $tingkat = $student->currentEnrollment()?->classroom?->tingkat;

        $types = FeeType::where('requires_selection', true)->where('is_active', true)->orderBy('sort_order')->get();

        $entries = $types->map(function (FeeType $type) use ($student, $year, $tingkat) {
            $rate = $year ? FeeRate::resolve($type, $student, $year, $tingkat) : null;

            if (! $rate) {
                return null;
            }

            $selection = StudentFeeSelection::where('student_id', $student->id)
                ->where('fee_rate_id', $rate->id)
                ->with('items')
                ->first();

            return [
                'fee_type' => ['ulid' => $type->ulid, 'name' => $type->name],
                'fee_rate' => [
                    'ulid' => $rate->ulid,
                    'components' => $rate->components->map(fn ($c) => [
                        'ulid' => $c->ulid,
                        'name' => $c->name,
                        'amount' => (float) $c->amount,
                        'default_qty' => $c->default_qty,
                        'is_optional' => $c->is_optional,
                        'has_size_option' => $c->has_size_option,
                        'size_options' => $c->sizeOptionList(),
                    ]),
                ],
                'selection' => $selection ? [
                    'submitted_at' => $selection->submitted_at,
                    'locked_at' => $selection->locked_at,
                    'items' => $selection->items->map(fn ($i) => [
                        'fee_component_ulid' => $i->component?->ulid,
                        'included' => $i->included,
                        'size_option' => $i->size_option,
                    ]),
                ] : null,
            ];
        })->filter()->values();

        return response()->json(['fee_selections' => $entries]);
    }

    public function store(Request $request, string $studentUlid, FeeSelectionService $service): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();

        $validated = $request->validate([
            'fee_rate_ulid' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.component_ulid' => 'required|string',
            'items.*.included' => 'boolean',
            'items.*.size_option' => 'nullable|string|max:20',
        ]);

        $rate = FeeRate::where('ulid', $validated['fee_rate_ulid'])->with('components', 'feeType')->firstOrFail();

        // A wali could otherwise name any fee_rate_ulid, including one from a
        // unit or year their own child was never rated for - recompute the
        // resolution the same way BillGenerator would and require it to
        // land on this exact rate before accepting a selection against it.
        $year = $rate->academic_year_id ? AcademicYear::find($rate->academic_year_id) : null;
        $tingkat = $student->currentEnrollment()?->classroom?->tingkat;
        $resolved = $year ? FeeRate::resolve($rate->feeType, $student, $year, $tingkat) : null;
        abort_if(! $resolved || $resolved->id !== $rate->id, 404);

        $items = collect($validated['items'])->map(function ($item) use ($rate) {
            $component = $rate->components->firstWhere('ulid', $item['component_ulid']);
            abort_if(! $component, 422, 'Komponen tidak ditemukan pada tarif ini.');

            return [
                'component' => $component,
                'included' => $item['included'] ?? true,
                'size_option' => $item['size_option'] ?? null,
            ];
        });

        try {
            $selection = $service->submit($student, $rate, $items);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['selection' => [
            'submitted_at' => $selection->submitted_at,
            'locked_at' => $selection->locked_at,
        ]], 201);
    }
}
