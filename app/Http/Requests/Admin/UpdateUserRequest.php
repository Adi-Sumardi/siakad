<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The route-bound user this update targets - unique-ignore needs it.
        $user = $this->route('user');

        return [
            'name' => 'sometimes|string|max:120',
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:32',
            'role' => 'sometimes|in:admin,admin_unit,guru,orangtua',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
        ];
    }
}
