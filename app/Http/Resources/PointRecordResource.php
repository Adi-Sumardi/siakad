<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointRecordResource extends JsonResource
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
            'type' => $this->type,
            'points' => $this->points,
            'occurred_on' => $this->occurred_on?->toDateString(),
            'description' => $this->description,
            'evidence_path' => $this->evidence_path ? true : false,
            'rule' => $this->whenLoaded('pointRule', fn () => $this->pointRule ? [
                'code' => $this->pointRule->code,
                'name' => $this->pointRule->name,
                'category' => $this->pointRule->category,
            ] : null),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'status' => $this->status,
            'revoked_at' => $this->revoked_at,
            'revoke_reason' => $this->revoke_reason,
            'acknowledged_at' => $this->acknowledged_at,
            'created_at' => $this->created_at,
        ];
    }
}
