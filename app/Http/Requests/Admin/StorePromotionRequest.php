<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The promotion roster: every student gets an explicit outcome (promoted,
 * repeated, graduated, left) and optionally the classroom they land in -
 * R10, new enrollment rows, never an overwritten classroom_id.
 */
class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_ulid' => 'required|string',
            'entries' => 'required|array|min:1|max:200',
            'entries.*.student_ulid' => 'required|string',
            'entries.*.outcome' => 'required|in:promoted,repeated,graduated,left',
            'entries.*.target_classroom_ulid' => 'nullable|string',
        ];
    }
}
