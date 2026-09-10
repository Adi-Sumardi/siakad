<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The year label is the identity every billing run and classroom keys on,
 * so it is both shape-checked (2027/2028) and unique up front.
 */
class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => 'required|string|regex:/^\d{4}\/\d{4}$/|unique:academic_years,year',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'is_active' => 'boolean',
        ];
    }
}
