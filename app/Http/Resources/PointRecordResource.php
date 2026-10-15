<?php

namespace App\Http\Resources;

use App\Models\PointApproval;
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
            'severity' => $this->severity,
            // Who has to act next while pending (wali_kelas / waka_kesiswaan /
            // kepala_sekolah) - the screen says "menunggu Wali Kelas".
            'approval_stage' => $this->approval_stage,
            'approval_stage_label' => $this->approval_stage ? (PointApproval::STAGE_LABEL[$this->approval_stage] ?? null) : null,
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn (PointApproval $a) => [
                'stage' => $a->stage,
                'stage_label' => PointApproval::STAGE_LABEL[$a->stage] ?? $a->stage,
                'decision' => $a->decision,
                'decided_by' => $a->decidedBy?->name,
                'note' => $a->note,
                'at' => $a->created_at,
            ])->values()),
            // Shared by every row of one bulk entry (audit 6 Okt 2026 #10).
            'batch_id' => $this->batch_id,
            'revoked_at' => $this->revoked_at,
            'revoke_reason' => $this->revoke_reason,
            'acknowledged_at' => $this->acknowledged_at,
            'created_at' => $this->created_at,
        ];
    }
}
