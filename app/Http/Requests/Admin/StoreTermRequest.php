<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A semester row. The DB column is an enum('ganjil','genap') (migration
 * 2026_08_14_000004), so the name is shape-checked here - the old free-text
 * rule validated fine and then died as a 500 the moment anyone typed
 * "Ganjil" with a capital G (audit T47). Unique within its academic year so
 * the picker never shows two identical labels for one year.
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
                Rule::in(['ganjil', 'genap']),
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
