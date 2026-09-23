<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Same full-payload shape as the store (the unit master is edited whole,
 * not patched), only the code may keep its own row on the unique index.
 */
class UpdateSchoolUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $schoolUnit = $this->route('schoolUnit');

        return [
            'code' => 'required|string|max:100|alpha_dash|unique:school_units,code,'.$schoolUnit->id,
            'label' => 'required|string|max:255',
            'jenjang_group' => 'required|in:ra,pg,tk,sd,smp,sma',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
