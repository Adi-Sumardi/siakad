<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Thresholds bracket point totals into labelled bands, so the range has to
 * be well-formed at the field level already: min strictly below max.
 */
class StorePointThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'school_unit_code' => 'nullable|exists:school_units,code',
            'min_points' => 'required|integer|lt:max_points',
            'max_points' => 'required|integer',
            'label' => 'required|string|max:80',
            'action' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:20',
            'notify_guardian' => 'boolean',
        ];
    }
}
