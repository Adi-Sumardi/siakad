<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StorePointRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ulid' => 'required|string',
            'point_rule_ulid' => 'required|string',
            // Laravel's bare 'today' keyword resolves against
            // config('app.timezone'), which is UTC (see ClassroomController's
            // note on the same root cause) - an explicit Jakarta date avoids
            // rejecting a same-day entry as "in the future" during the seven
            // hours every morning UTC's calendar date still lags Jakarta's.
            'occurred_on' => ['required', 'date', 'before_or_equal:'.Carbon::today('Asia/Jakarta')->toDateString()],
            'description' => 'required|string|max:1000',
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
