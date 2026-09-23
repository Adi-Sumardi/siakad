<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An extracurricular runs under one academic year with an optional
 * supervising teacher (pembina) and a capacity the roster enforces.
 */
class StoreExtracurricularRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:1000',
            'school_unit_code' => 'nullable|exists:school_units,code',
            'academic_year_ulid' => 'required|string',
            'pembina_ulid' => 'nullable|string',
            'capacity' => 'nullable|integer|min:1|max:500',
        ];
    }
}
