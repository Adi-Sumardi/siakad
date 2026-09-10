<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The per-unit, per-year price sheet - optionally narrowed to one tingkat,
 * with an optional breakdown (components) the bill can itemise.
 */
class StoreFeeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fee_type_ulid' => 'required|exists:fee_types,ulid',
            'school_unit_ulid' => 'required|exists:school_units,ulid',
            'academic_year_ulid' => 'required|exists:academic_years,ulid',
            'tingkat' => 'nullable|integer|min:1|max:12',
            'amount' => 'required|numeric|min:0',
            'due_day' => 'nullable|integer|min:1|max:28',
            'late_fee_amount' => 'numeric|min:0',
            'late_fee_grace_days' => 'integer|min:0',
            'notes' => 'nullable|string|max:500',

            'components' => 'array',
            'components.*.name' => 'required_with:components|string|max:120',
            'components.*.amount' => 'required_with:components|numeric|min:0',
            'components.*.default_qty' => 'integer|min:1',
            'components.*.is_optional' => 'boolean',
            'components.*.has_size_option' => 'boolean',
            'components.*.size_options' => 'nullable|string|max:255',
        ];
    }
}
