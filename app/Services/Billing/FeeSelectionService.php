<?php

namespace App\Services\Billing;

use App\Models\FeeRate;
use App\Models\Student;
use App\Models\StudentFeeSelection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of student_fee_selections. A required component is always
 * included regardless of what the request says - a family chooses sizes, not
 * whether the shirt is mandatory. Once BillGenerator has issued a bill from a
 * selection it is locked (see locked_at on the model) and this refuses
 * further edits, so a printed kuitansi can never drift from what was chosen.
 */
class FeeSelectionService
{
    /**
     * @param  Collection<int, array{component: \App\Models\FeeComponent, included: bool, size_option: ?string}>  $items
     */
    public function submit(Student $student, FeeRate $rate, Collection $items): StudentFeeSelection
    {
        $existing = StudentFeeSelection::where('student_id', $student->id)
            ->where('fee_rate_id', $rate->id)
            ->first();

        if ($existing?->isLocked()) {
            throw new RuntimeException('Pilihan ini sudah ditagih dan tidak bisa diubah lagi.');
        }

        foreach ($items as $item) {
            $component = $item['component'];
            $included = $component->is_optional ? (bool) $item['included'] : true;

            if ($included && $component->has_size_option) {
                $size = $item['size_option'] ?? null;
                if (! $size || ! in_array($size, $component->sizeOptionList(), true)) {
                    throw new RuntimeException("Pilih ukuran yang valid untuk '{$component->name}'.");
                }
            }
        }

        return DB::transaction(function () use ($student, $rate, $items, $existing) {
            $selection = StudentFeeSelection::updateOrCreate(
                ['student_id' => $student->id, 'fee_rate_id' => $rate->id],
                ['submitted_at' => now()],
            );

            $selection->items()->delete();

            foreach ($items as $item) {
                $component = $item['component'];
                $included = $component->is_optional ? (bool) $item['included'] : true;

                $selection->items()->create([
                    'fee_component_id' => $component->id,
                    'included' => $included,
                    'size_option' => $included && $component->has_size_option ? $item['size_option'] : null,
                ]);
            }

            return $selection->fresh('items.component');
        });
    }

    /** SUM of included components - what BillGenerator charges instead of the rate's flat amount. */
    public function computeTotal(StudentFeeSelection $selection): float
    {
        return (float) $selection->items()
            ->where('included', true)
            ->with('component')
            ->get()
            ->sum(fn ($item) => (float) $item->component->amount * $item->component->default_qty);
    }
}
