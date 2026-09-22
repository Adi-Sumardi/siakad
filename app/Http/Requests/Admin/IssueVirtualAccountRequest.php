<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin minting a Virtual Account for one bill on the family's behalf
 * ("Buat VA" on the tagihan page). The bank is the only choice to make - the
 * amount, payer, and expiry all follow the bill and the gateway's own rules.
 */
class IssueVirtualAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank' => ['nullable', 'in:muamalat,bsi'],
        ];
    }
}
