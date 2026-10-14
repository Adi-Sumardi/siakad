<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // NIP PNS is 18 digits, but yayasan staff numbers vary - digits,
            // dots and spaces only, generous length.
            'nip' => ['nullable', 'string', 'max:30', 'regex:/^[0-9. ]+$/'],
            'jabatan' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return ['nip.regex' => 'NIP hanya boleh berisi angka.'];
    }

    public function attributes(): array
    {
        return ['nip' => 'NIP', 'jabatan' => 'jabatan'];
    }
}
