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
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Nominal tagihan minimal Rp 1.000.',
            'amount.max' => 'Nominal tagihan terlalu besar.',
        ];
    }
}
