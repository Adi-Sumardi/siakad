<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One shape for every ledger revoke a teacher performs (point record,
 * attendance record): R2 - a revoke is never a delete, the reason is the
 * audit trail that stays on the row.
 */
class RevokeReasonRequest extends FormRequest
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
