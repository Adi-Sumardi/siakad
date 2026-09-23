<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The login identifier (email or WhatsApp number) - deliberately loose:
 * normalisation and channel detection happen in OtpService, and a strict
 * format here would leak which shape is valid before the rate limiter
 * has had its say.
 */
class OtpRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string|max:200',
        ];
    }
}
