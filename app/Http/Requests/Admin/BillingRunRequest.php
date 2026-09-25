<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by the billing run's dry-run preview and its live execution. */
class BillingRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fee_type_code' => 'required|exists:fee_types,code',
            'month' => 'nullable|integer|min:1|max:12',
            'unit_code' => 'nullable|exists:school_units,code',
            'due_date' => 'nullable|date',
            // The PRINTING date (user's explicit choice, Poin 10): what the
            // bills carry as issued_at - backdating a late-issued month's
            // bills is the point. The due date keeps coming from due_day /
            // due_date above, untouched.
            'issued_at' => 'nullable|date',
        ];
    }
}
