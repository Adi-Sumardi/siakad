<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:120',
            // `code` is deliberately absent: it is the key the generator and
            // every dedup_key already written are built on. Renaming it would
            // orphan bills that were issued under the old one.
            'allow_installment' => 'boolean',
            'requires_selection' => 'boolean',
            'requires_roster_membership' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
