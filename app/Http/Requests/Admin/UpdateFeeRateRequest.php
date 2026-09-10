<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'sometimes|numeric|min:0',
            'due_day' => 'nullable|integer|min:1|max:28',
            'late_fee_amount' => 'numeric|min:0',
            'late_fee_grace_days' => 'integer|min:0',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
