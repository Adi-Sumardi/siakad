<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:32|alpha_dash|unique:discount_schemes,code',
            'name' => 'required|string|max:120',
            'type' => 'required|in:percent,nominal',
            'value' => 'required|numeric|min:0',
            'fee_type_ulid' => 'nullable|exists:fee_types,ulid',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
