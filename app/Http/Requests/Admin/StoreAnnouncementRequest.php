<?php

namespace App\Http\Requests\Admin;

use App\Support\Jenjang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Multi-jenjang targeting (Poin 8): any mix of ladder keys
            // ("sd-1", "tk-b", the coarse "smp", …). Stored structured in
            // announcement_targets so the feed can match each student's own
            // rung, and the history can be re-queried later.
            'jenjang' => 'nullable|array',
            'jenjang.*' => ['string', 'distinct', Rule::in(array_merge(Jenjang::keys(), Jenjang::GROUPS))],
            'is_pinned' => 'boolean',
            'published_at' => 'nullable|date',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}

