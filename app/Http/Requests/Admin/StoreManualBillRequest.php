<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A one-off bill an admin issues by hand - the unexpected cases the
 * scheduled generator never knows about (a replacement uniform, a mid-year
 * entry month, a damaged book). The fee type must come from the existing
 * catalogue: money context stays identical to generated bills.
 */
class StoreManualBillRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_ulid' => ['required', 'ulid'],
            'fee_type_ulid' => ['required', 'ulid'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:1000', 'max:100000000'],
            'due_date' => ['required', 'date'],

            // Itemised breakdown (e.g. "Program Cambridge" + "Buku" on one
            // cambridge bill, paid with one VA). Absent = the single
            // description/amount line, exactly as before.
            'lines' => ['nullable', 'array', 'min:1', 'max:20'],
            'lines.*.name' => ['required_with:lines', 'string', 'max:200'],
            'lines.*.qty' => ['required_with:lines', 'integer', 'min:1', 'max:99'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:1', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Nominal tagihan minimal Rp 1.000.',
            'amount.max' => 'Nominal tagihan terlalu besar.',
        ];
    }

    /**
     * When a breakdown is given, it must add up to the bill's own total - a
     * mismatch would make the itemised receipt and the charged amount tell
     * two different stories.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $lines = $this->input('lines');

            if (! is_array($lines) || $lines === []) {
                return;
            }

            $sum = 0.0;
            foreach ($lines as $line) {
                $sum += (int) ($line['qty'] ?? 0) * (float) ($line['unit_price'] ?? 0);
            }

            if (abs($sum - (float) $this->input('amount', 0)) > 0.01) {
                $validator->errors()->add('lines', 'Total rincian harus sama dengan nominal tagihan.');
            }
        });
    }
}
