<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:60',
            'tingkat' => 'sometimes|integer|min:1|max:12',
            'capacity' => 'nullable|integer|min:1|max:100',
            'homeroom_teacher_ulid' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
