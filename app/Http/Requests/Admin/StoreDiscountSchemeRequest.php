<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiscountSchemeRequest extends FormRequest
{
    /**
     * The scholarship kinds (feature batch Poin 13) - one shared list so the
     * UI dropdown and this validation can never drift.
     */
    public const JENIS = ['beasiswa_penuh', 'beasiswa_parsial', 'keringanan', 'diskon_karyawan', 'lainnya'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:32|alpha_dash|unique:discount_schemes,code',
            'name' => 'required|string|max:120',
            'type' => 'required|in:percent,nominal',
            // Nullable on purpose: the column defaults to 'lainnya', and
            // older callers/tests that never send it stay valid.
            'jenis' => ['nullable', Rule::in(self::JENIS)],
            'value' => 'required|numeric|min:0',
            'fee_type_ulid' => 'nullable|exists:fee_types,ulid',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
