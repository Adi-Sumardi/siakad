<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExtracurricularRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:80',
            'description' => 'nullable|string|max:1000',
            'pembina_ulid' => 'nullable|string',
            'capacity' => 'nullable|integer|min:1|max:500',
            'is_active' => 'boolean',
        ];
    }
}
