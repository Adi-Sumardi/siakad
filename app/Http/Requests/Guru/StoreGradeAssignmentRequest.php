<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/** One Tugas column: a short title the teacher recognises, optionally its date. */
class StoreGradeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
            'assigned_on' => 'nullable|date',
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'nama tugas', 'assigned_on' => 'tanggal tugas'];
    }
}
