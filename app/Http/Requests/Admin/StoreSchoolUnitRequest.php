<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSchoolUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:100|alpha_dash|unique:school_units,code',
            'label' => 'required|string|max:255',
            'jenjang_group' => 'required|in:ra,pg,tk,sd,smp,sma',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
