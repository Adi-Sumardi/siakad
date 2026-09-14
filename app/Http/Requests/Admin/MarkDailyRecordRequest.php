<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One TU mark against a daily session - the manual lane of gate mode. Same
 * shape as the guru panel's MarkDailyAttendanceRequest: 'terlambat' is a
 * first-class choice here because that is what the roster UI offers; the
 * ledger stores it as hadir + is_late (see DailyAttendanceService::mark).
 */
class MarkDailyRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ulid' => ['required', 'string'],
            'status' => ['required', 'in:hadir,terlambat,sakit,izin,alpa'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
