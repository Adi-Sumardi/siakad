<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:200',
            'body' => 'sometimes|string|max:5000',
            'jenjang' => 'nullable|array',
            'jenjang.*' => ['string', 'distinct', \Illuminate\Validation\Rule::in(\App\Support\Jenjang::keys())],
            'is_pinned' => 'boolean',
            'published_at' => 'nullable|date',
        ];
    }
}
