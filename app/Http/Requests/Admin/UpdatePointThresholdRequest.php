<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePointThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_points' => 'sometimes|integer',
            'max_points' => 'sometimes|integer',
            'label' => 'sometimes|string|max:80',
            'action' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:20',
            'notify_guardian' => 'boolean',
        ];
    }
}
