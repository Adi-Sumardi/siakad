<?php

namespace App\Http\Requests\Wali;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A wali tailoring one fee rate to their child: which components to
 * include, and the size where the component offers one.
 */
class StoreFeeSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fee_rate_ulid' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.component_ulid' => 'required|string',
            'items.*.included' => 'boolean',
            'items.*.size_option' => 'nullable|string|max:20',
        ];
    }
}
