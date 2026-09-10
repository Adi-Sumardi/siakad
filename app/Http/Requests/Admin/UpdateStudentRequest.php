<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The route-bound student this update targets - unique-ignore needs it.
        $student = $this->route('student');

        return [
            'nama_lengkap' => 'sometimes|string|max:255',
            'nama_panggilan' => 'nullable|string|max:100',
            'nis' => ['nullable', 'string', 'max:50', Rule::unique('students', 'nis')->ignore($student->id)],
            'nisn' => 'nullable|string|max:50',
            'jenis_kelamin' => 'sometimes|in:L,P',
            'school_unit_ulid' => 'sometimes|exists:school_units,ulid',
            'status' => 'sometimes|in:prospective,active,graduated,transferred,dropped_out',
        ];
    }
}
