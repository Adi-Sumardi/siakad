<?php

namespace App\Http\Requests\Wali;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying a basket of bills: method picks the rail, bank only applies to
 * virtual accounts, and custom_amounts carries partial payments.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bill_ulids' => 'required|array|min:1|max:50',
            'bill_ulids.*' => 'required|string',
            'method' => 'required|in:virtual_account,e_wallet,qris,bank_transfer,credit_card',
            'bank' => 'nullable|in:muamalat,bsi',
            'custom_amounts' => 'nullable|array',
            'custom_amounts.*' => 'numeric|min:1',
        ];
    }
}
