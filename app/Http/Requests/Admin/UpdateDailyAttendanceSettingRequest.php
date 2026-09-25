<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A unit's daily-attendance bells and gate policy (DESAIN-PRESENSI-HARIAN.md
 * §5A). Everything is conditional on purpose: pulang times only matter when
 * pulang is switched on, gate coordinates only when the radius check is, and
 * the late threshold must sit inside the masuk window so "terlambat" is
 * reachable before "ditutup".
 */
class UpdateDailyAttendanceSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'days' => ['array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],

            'masuk_opens_at' => ['required', 'date_format:H:i'],
            'masuk_closes_at' => ['required', 'date_format:H:i', 'after:masuk_opens_at'],
            'masuk_late_after' => ['nullable', 'date_format:H:i', 'after:masuk_opens_at', 'before:masuk_closes_at'],

            'pulang_enabled' => ['boolean'],
            'pulang_opens_at' => [
                Rule::when(fn () => $this->boolean('pulang_enabled'), ['required', 'date_format:H:i']),
                // The pulang window must not open before masuk closes (audit
                // T65-b): an overlap made the gate serve MASUK for the whole
                // overlap (a student leaving early could not scan pulang),
                // and the device-once rule lost its cross-window guarantee.
                Rule::when(fn () => $this->boolean('pulang_enabled'), ['after_or_equal:masuk_closes_at']),
                'nullable',
            ],
            'pulang_closes_at' => [
                Rule::when(fn () => $this->boolean('pulang_enabled'), ['required', 'date_format:H:i', 'after:pulang_opens_at']),
                'nullable',
            ],

            'intake_mode' => ['nullable', 'in:wali_kelas,gerbang'],

            'geo_required' => ['boolean'],
            'gate_lat' => [
                Rule::when(fn () => $this->boolean('geo_required'), ['required', 'numeric', 'between:-90,90']),
                'nullable', 'numeric', 'between:-90,90',
            ],
            'gate_lng' => [
                Rule::when(fn () => $this->boolean('geo_required'), ['required', 'numeric', 'between:-180,180']),
                'nullable', 'numeric', 'between:-180,180',
            ],
            'geo_radius_m' => [
                // 30 m floors would reject honest phones - GPS wanders
                // +-10-50 m in built-up areas (see the design doc §6).
                Rule::when(fn () => $this->boolean('geo_required'), ['required', 'integer', 'min:30', 'max:2000']),
                'nullable', 'integer', 'min:30', 'max:2000',
            ],

            'qr_required' => ['boolean'],
            'notify_masuk' => ['boolean'],
            'notify_pulang' => ['boolean'],
            'notify_absent' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'masuk_closes_at.after' => 'Jam tutup absen masuk harus setelah jam bukanya.',
            'masuk_late_after.after' => 'Batas terlambat harus setelah jam buka absen masuk.',
            'masuk_late_after.before' => 'Batas terlambat harus sebelum absen masuk ditutup.',
            'pulang_opens_at.after_or_equal' => 'Jam buka absen pulang tidak boleh lebih awal dari jam tutup absen masuk.',
            'pulang_closes_at.after' => 'Jam tutup absen pulang harus setelah jam bukanya.',
        ];
    }
}
