<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Code and type stay locked on edit (T3): the code is the catalogue key the
 * ledger rows point at, and points already copied into the ledger must not
 * silently change meaning under them - which is why neither field is here.
 */
class UpdatePointRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:120',
            'category' => 'sometimes|string|max:60',
            'points' => 'sometimes|integer|min:1|max:200',
            'requires_evidence' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
