<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:32|alpha_dash|unique:fee_types,code',
            'name' => 'required|string|max:120',
            'recurrence' => 'required|in:monthly,per_term,once',
            'allow_installment' => 'boolean',
            'requires_selection' => 'boolean',
            // Mirrors requires_selection - only ekskul uses it today, but any
            // future fee type tied to a roster (extracurricular_members-style
            // table) can opt in the same way instead of a new hardcoded check.
            'requires_roster_membership' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
