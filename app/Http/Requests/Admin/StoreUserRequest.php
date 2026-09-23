<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T10 phase 1: the money and account endpoints move their inline validation
 * here so the rules live next to each other instead of scattered across
 * controller bodies. Authorisation stays in the route's role middleware -
 * a FormRequest authorize() duplicating it would be a second place the same
 * decision can drift.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:120|unique:users,email',
            'phone' => 'nullable|string|max:32',
            'role' => 'required|in:admin,admin_unit,guru,orangtua',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
        ];
    }
}
