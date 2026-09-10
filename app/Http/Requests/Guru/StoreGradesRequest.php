<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One category's column of the gradebook: scores are 0-100, entered for a
 * batch of students at once.
 */
class StoreGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => 'required|in:tugas,uts,uas',
            'entries' => 'required|array|min:1|max:200',
            'entries.*.student_ulid' => 'required|string',
            'entries.*.score' => 'required|numeric|min:0|max:100',
            'entries.*.description' => 'nullable|string|max:500',
        ];
    }
}
