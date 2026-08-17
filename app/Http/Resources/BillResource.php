<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'bill_number' => $this->bill_number,
            'description' => $this->description,
            'fee_type' => $this->whenLoaded('feeType', fn () => [
                'code' => $this->feeType->code,
                'name' => $this->feeType->name,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'ulid' => $this->student->ulid,
                'nama_lengkap' => $this->student->nama_lengkap,
                'nama_panggilan' => $this->student->nama_panggilan,
            ]),
            'period_month' => $this->period_month,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'late_fee' => (float) $this->late_fee,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            // Days, signed: negative means overdue. The UI phrases it ("4 hari
            // lagi", "telat 12 hari") because that is what decides the action,
            // not the calendar date.
            'days_to_due' => $this->due_date ? (int) now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false) : null,
            'lines' => BillLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
