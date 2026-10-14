<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The gradebook sheet's changed cells. Per student: tugas (assignment ULID ->
 * score), uts, uas. null clears a cell; an absent key leaves it alone.
 */
class SaveGradeSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entries' => 'required|array|min:1|max:200',
            'entries.*.student_ulid' => 'required|string',
            'entries.*.tugas' => 'sometimes|array|max:100',
            'entries.*.tugas.*' => 'nullable|numeric|min:0|max:100',
            'entries.*.uts' => 'sometimes|nullable|numeric|min:0|max:100',
            'entries.*.uas' => 'sometimes|nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'entries.*.tugas.*.min' => 'Nilai harus antara 0 dan 100.',
            'entries.*.tugas.*.max' => 'Nilai harus antara 0 dan 100.',
            'entries.*.uts.min' => 'Nilai UTS harus antara 0 dan 100.',
            'entries.*.uts.max' => 'Nilai UTS harus antara 0 dan 100.',
            'entries.*.uas.min' => 'Nilai UAS harus antara 0 dan 100.',
            'entries.*.uas.max' => 'Nilai UAS harus antara 0 dan 100.',
        ];
    }
}
