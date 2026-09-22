<?php

namespace App\Http\Requests\Admin;

use App\Services\Notification\PhoneNumberFormatter;
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

        $phone = PhoneNumberFormatter::toWhatsAppFormat($raw);

        // A number with at least 9 digits (08 + 7+) is a phone; anything else
        // is a typo, not a channel decision.
        if ($phone !== null && strlen($phone) >= 9) {
            $this->channel = 'whatsapp';
            $this->normalized = $phone;

            return;
        }

        $this->channel = null;
        $this->normalized = $raw;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->channel === null) {
                $validator->errors()->add('contact', 'Isi dengan alamat email atau nomor HP/WhatsApp yang valid.');
            }
        });
    }
}
