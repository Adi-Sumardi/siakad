<?php

namespace App\Http\Requests\Admin;

use App\Services\Academic\WatchlistService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query filters of the /admin/perhatian drill-down: narrow the watchlist to
 * one condition, and/or one school unit (central admin only - a unit admin's
 * scope is already narrowed by visibleTo()).
 */
class AttentionFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gate lives on the route middleware, not here
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', Rule::in(WatchlistService::REASONS)],
            'unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
