<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Cash at the front desk, or a transfer the admin has already confirmed. */
class RecordBillPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cash,bank_transfer,qris,other',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
