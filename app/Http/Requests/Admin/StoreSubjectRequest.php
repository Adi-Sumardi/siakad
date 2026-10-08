<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A subject is a name plus the grade levels it runs in. The code is no
 * longer asked for (it is not used by rapor or reports); it stays optional
 * for anyone who wants to set one through the API.
 */
class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'school_unit_code' => 'nullable|exists:school_units,code',
            'code' => 'nullable|string|max:32|alpha_dash',
            'name' => 'required|string|max:120',
            'tingkat' => 'required|array|min:1',
            'tingkat.*' => 'integer|distinct|min:1|max:12',
        ];
    }

    public function messages(): array
    {
        return [
            'tingkat.required' => 'Pilih minimal satu tingkat.',
            'tingkat.min' => 'Pilih minimal satu tingkat.',
        ];
    }
}
