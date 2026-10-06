<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'student' => $this->whenLoaded('student', fn () => [
                'ulid' => $this->student->ulid,
                'nama_lengkap' => $this->student->nama_lengkap,
                'nis' => $this->student->nis,
                'classroom' => $this->student->currentEnrollment()?->classroom?->name,
            ]),
            'type' => $this->type,
            'date_from' => $this->date_from?->toDateString(),
            'date_to' => $this->date_to?->toDateString(),
            'reason' => $this->reason,
            'has_attachment' => (bool) $this->attachment_path,
            'attachment_name' => $this->attachment_name,
            'status' => $this->status,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at,
        ];
    }
}
