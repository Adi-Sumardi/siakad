<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A semester row. Names are free text (the school says ganjil/genap, but a
 * unit running a short inter-session term should not have to fight the
 * validation), unique within its academic year so the picker never shows
 * two identical labels for one year.
 */
class StoreTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_ulid' => [
                'required',
                'ulid',
                Rule::exists('academic_years', 'ulid'),
            ],
            'name' => [
                'required',
                'string',
                'max:30',
                Rule::unique('terms', 'name')->where('academic_year_id', $this->academicYearId()),
            ],
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'is_active' => 'boolean',
        ];
    }

    private function academicYearId(): ?int
    {
        $ulid = (string) $this->input('academic_year_ulid');

        if ($ulid === '') {
            return null;
        }

        return \App\Models\AcademicYear::where('ulid', $ulid)->value('id');
    }
}
