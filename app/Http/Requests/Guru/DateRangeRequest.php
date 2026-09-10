<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional from/to window on the attendance recap - the controller
 * defaults it to the active term, this only narrows it.
 */
class DateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ];
    }
}
