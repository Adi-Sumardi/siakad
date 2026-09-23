<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One shape for both money write-offs that need a stated reason (waive and
 * cancel): the reason is the audit trail - a balance that silently
 * disappeared with nobody able to account for why is the alternative.
 */
class BillReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:500',
        ];
    }
}
