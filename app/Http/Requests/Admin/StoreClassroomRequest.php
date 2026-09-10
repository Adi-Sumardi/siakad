<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A classroom belongs to exactly one unit and one academic year - the unit
 * is resolved from school_unit_code in the controller (unit admins get
 * their own forced in), never trusted from this payload alone.
 */
class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:60',
            'tingkat' => 'required|integer|min:1|max:12',
            'school_unit_code' => 'nullable|exists:school_units,code',
            'academic_year_ulid' => 'required|string',
            'capacity' => 'nullable|integer|min:1|max:100',
            'homeroom_teacher_ulid' => 'nullable|string',
        ];
    }
}
