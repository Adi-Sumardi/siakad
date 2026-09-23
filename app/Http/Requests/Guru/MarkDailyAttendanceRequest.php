<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One homeroom-teacher mark against a daily session. 'terlambat' is a first
 * -class choice here because that is what the roster UI offers; the ledger
 * stores it as hadir + is_late (see DailyAttendanceService::mark).
 */
class MarkDailyAttendanceRequest extends FormRequest
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
