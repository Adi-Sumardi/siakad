<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One shape for both presensi endpoints (lookup and check-in): the NIS the
 * student types at the gate. check-in re-resolves everything server-side
 * rather than trusting the client's lookup result.
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
        ];
    }
}
