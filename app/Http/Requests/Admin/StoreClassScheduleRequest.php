<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The weekly timetable slot: days run 1-6 (Monday-Saturday), times are
 * plain H:i clock strings, and a period must end after it starts.
 */
class StoreClassScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_ulid' => 'required|string',
            'teacher_ulid' => 'nullable|string',
            'day_of_week' => 'required|integer|min:1|max:6',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ];
    }
}
