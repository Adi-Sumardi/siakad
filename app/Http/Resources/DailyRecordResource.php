<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'date' => $this->date?->toDateString(),
            'type' => $this->dailySession?->type,
            'attendance_status' => $this->attendance_status,
            'is_late' => $this->is_late,
            'description' => $this->description,
            'checked_in_at' => $this->checked_in_at?->format('H:i'),
            'record_status' => $this->record_status,
            'revoke_reason' => $this->revoke_reason,
        ];
    }
}
