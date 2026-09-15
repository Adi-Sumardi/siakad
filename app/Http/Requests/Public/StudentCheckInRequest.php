<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One shape for both presensi endpoints (lookup and check-in): the NIS the
 * student types in class, plus - check-in only - the browser's device id and
 * the rotating QR code from the teacher's screen. check-in re-resolves
 * everything server-side rather than trusting the client's lookup result.
 */
class StudentCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nis' => 'required|string|max:50',
            'device_id' => 'nullable|string|max:64',
            'qr_code' => 'nullable|string|max:32',
        ];
    }
}
