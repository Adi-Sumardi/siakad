<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
            'code' => 'required|string|max:32|alpha_dash',
            'name' => 'required|string|max:120',
        ];
    }
}
