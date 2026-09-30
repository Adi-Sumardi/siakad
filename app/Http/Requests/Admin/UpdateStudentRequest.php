<?php

namespace App\Http\Requests\Admin;

use App\Models\SchoolUnit;
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

        // NIS is unique per unit, not across the foundation (2026-09-30, same
        // rule PMB now assigns it under) - two units may reuse a number. The
        // unit it's checked in is the one the student ends up in.
        $unitId = $this->filled('school_unit_ulid')
            ? SchoolUnit::where('ulid', $this->input('school_unit_ulid'))->value('id')
            : $student->school_unit_id;

        return [
            'nama_lengkap' => 'sometimes|string|max:255',
            'nama_panggilan' => 'nullable|string|max:100',
            'nis' => ['nullable', 'string', 'max:50', Rule::unique('students', 'nis')->where('school_unit_id', $unitId)->ignore($student->id)],
            'nisn' => 'nullable|string|max:50',
            'jenis_kelamin' => 'sometimes|in:L,P',
            'school_unit_ulid' => 'sometimes|exists:school_units,ulid',
            'status' => 'sometimes|in:prospective,active,graduated,transferred,dropped_out',
        ];
    }
}
