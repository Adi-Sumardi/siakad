<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Catalogue entry for a point rule - points are capped at 200 so a typo
 * can never hand a student a four-digit merit in one go.
 */
class StorePointRuleRequest extends FormRequest
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
            'type' => 'required|in:violation,merit',
            'category' => 'required|string|max:60',
            'points' => 'required|integer|min:1|max:200',
            'requires_evidence' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
