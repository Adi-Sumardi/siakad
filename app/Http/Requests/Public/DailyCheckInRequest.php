<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a student's phone posts at the gate. The NIS identifies them; the
 * device id is the browser's own localStorage UUID (the server stores only
 * its sha256 - the device-once rule's token); coordinates and the scanned
 * QR code are optional in shape but demanded by the unit's settings, which
 * the service re-checks - nothing here is trusted as-is.
 */
class DailyCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nis' => ['required', 'string', 'max:50'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'qr_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
