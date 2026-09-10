<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaches a scheme to one student for one academic year - the window has
 * to be a proper range so a discount never lapses before it starts.
 */
class AssignStudentDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ulid' => 'required|exists:students,ulid',
            'discount_scheme_ulid' => 'required|exists:discount_schemes,ulid',
            'academic_year_ulid' => 'required|exists:academic_years,ulid',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
