<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query filters of the executive dashboard summary: which period the MONEY
 * numbers cover. 'year' (default) scopes billed/paid/outstanding/overdue to
 * the running academic year - the same anchor the student and classroom
 * numbers use; 'all' restores the old all-time totals, kept switchable while
 * the school decides how the finance overview should be periodised (demo
 * question no. 27).
 */
class DashboardSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gate lives on the route middleware, not here
    }

    public function rules(): array
    {
        return [
            'billing_period' => ['nullable', Rule::in(['year', 'all'])],
        ];
    }
}
