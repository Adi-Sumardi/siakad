<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'student' => $this->whenLoaded('student', fn () => [
                'ulid' => $this->student->ulid,
                'nama_lengkap' => $this->student->nama_lengkap,
                'nama_panggilan' => $this->student->nama_panggilan,
            ]),
            'classroom' => $this->whenLoaded('classroom', fn () => $this->classroom?->name),
            'attendance_status' => $this->attendance_status,
            'occurred_on' => $this->occurred_on?->toDateString(),
            'description' => $this->description,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'record_status' => $this->record_status,
            'revoked_at' => $this->revoked_at,
            'revoke_reason' => $this->revoke_reason,
            'created_at' => $this->created_at,
        ];
    }
}
