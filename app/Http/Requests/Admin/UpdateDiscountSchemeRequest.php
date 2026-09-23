<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Code and type stay locked on edit - the code is the key bills already
 * written reference, and switching percent<->nominal would silently change
 * what every outstanding discount means.
 */
class UpdateDiscountSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:120',
            'type' => 'sometimes|in:percent,nominal',
            'value' => 'sometimes|numeric|min:0',
            'fee_type_ulid' => 'nullable|exists:fee_types,ulid',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
