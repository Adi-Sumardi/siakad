<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The manual sweep when a teacher closes a session: every student the QR
 * window missed gets an explicit status, so nobody is left unmarked.
 */
class CompleteAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records' => 'array|max:200',
            'records.*.student_ulid' => 'required_with:records|string',
            'records.*.status' => 'required_with:records|in:hadir,sakit,izin,alpa',
            'records.*.description' => 'nullable|string|max:500',
        ];
    }
}
