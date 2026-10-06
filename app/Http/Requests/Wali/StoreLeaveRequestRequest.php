<?php

namespace App\Http\Requests\Wali;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $today = Carbon::now('Asia/Jakarta');

        return [
            'type' => 'required|in:sakit,izin',
            'date_from' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$today->copy()->subDays(LeaveRequest::BACKDATE_DAYS)->toDateString(),
                'before_or_equal:'.$today->copy()->addDays(30)->toDateString(),
            ],
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'reason' => 'required|string|min:5|max:1000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if (! $validator->errors()->hasAny(['date_from', 'date_to'])
                && Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > LeaveRequest::MAX_DAYS) {
                $validator->errors()->add('date_to', 'Pengajuan paling lama '.LeaveRequest::MAX_DAYS.' hari - untuk lebih dari itu hubungi sekolah.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'date_from.after_or_equal' => 'Pengajuan untuk hari yang sudah lewat paling lambat '.LeaveRequest::BACKDATE_DAYS.' hari.',
            'date_from.before_or_equal' => 'Pengajuan paling jauh 30 hari ke depan.',
            'date_to.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'reason.min' => 'Tuliskan alasan singkat (minimal 5 karakter).',
        ];
    }
}
