<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Code stays locked on edit, same deal as point rules (T3): it is the
 * catalogue key schedules and grades point at, and renaming it in place
 * would silently change what every existing row means. Fixing a typo in
 * the code means deactivating this subject and creating the right one.
 */
class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:120',
            'is_active' => 'boolean',
        ];
    }
}
