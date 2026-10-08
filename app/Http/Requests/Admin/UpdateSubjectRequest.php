<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The code stays locked on edit (T3) - not exposed in the UI any more, and
 * still never rewritten in place. `tingkat` is the full list of grade levels
 * the subject should be active in; see SubjectController::syncTingkat().
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
            'tingkat' => 'sometimes|array|min:1',
            'tingkat.*' => 'integer|distinct|min:1|max:12',
        ];
    }

    public function messages(): array
    {
        return [
            'tingkat.min' => 'Pilih minimal satu tingkat.',
        ];
    }
}
