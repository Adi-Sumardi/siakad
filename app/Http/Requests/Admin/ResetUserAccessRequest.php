<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The new contact a reset invitation goes to - typed by an admin who checked
 * the person's identity in person, precisely because the account's own
 * contacts are unreachable.
 */
class ResetUserAccessRequest extends FormRequest
{
    public ?string $channel = null;

    public ?string $normalized = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact.required' => 'Kontak baru wajib diisi.',
        ];
    }

    /**
     * Normalises before anything is looked up or stored: OTP hashes the
     * identifier, so "+62812…" and "0812…" must resolve to one value before
     * the duplicate check and the invitation row ever see it.
     */
    protected function prepareForValidation(): void
    {
        $raw = trim((string) $this->input('contact'));

        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $this->channel = 'email';
            $this->normalized = mb_strtolower($raw);

            return;
        }

        // Email only (2026-10-14): a phone number is refused, not sent over
        // WhatsApp - the school sends invitations and resets by email.
        $this->channel = null;
        $this->normalized = $raw;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->channel === null) {
                $validator->errors()->add('contact', 'Isi dengan alamat email yang valid - tautan reset hanya dikirim lewat email.');
            }
        });
    }
}
