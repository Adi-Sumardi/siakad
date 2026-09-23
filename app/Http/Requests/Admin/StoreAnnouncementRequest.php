<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An attachment rides along as multipart - images and PDF only, capped at
 * 5 MB, the formats a school newsletter realistically carries.
 */
class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'school_unit_code' => 'nullable|exists:school_units,code',
            'classroom_ulid' => 'nullable|string',
            'is_pinned' => 'boolean',
            'published_at' => 'nullable|date',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
