<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The PMB handoff event: event_id drives the idempotency (unique index +
 * firstOrCreate), so a redelivered event is answered, not re-processed.
 */
class PmbHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event' => 'required|string|in:student.enrolled,student.updated,student.cancelled',
            'event_id' => 'required|string|max:64',
            'occurred_at' => 'nullable|date',

            'student' => 'required|array',
            'student.pmb_ulid' => 'required|string|max:64',
            'student.nama_lengkap' => 'required|string|max:200',
            'student.jenis_kelamin' => 'required|in:L,P',
            'student.unit_code' => 'required|string|max:64',
            'student.academic_year' => 'nullable|string|max:16',
            'student.tanggal_lahir' => 'nullable|date',

            'guardians' => 'array',
            'guardians.*.nama' => 'required_with:guardians|string|max:200',
            'guardians.*.hubungan' => 'required_with:guardians|in:ayah,ibu,wali',
            'guardians.*.email' => 'nullable|email|max:200',
            'guardians.*.no_hp' => 'nullable|string|max:32',
            'guardians.*.is_primary' => 'boolean',
        ];
    }
}
