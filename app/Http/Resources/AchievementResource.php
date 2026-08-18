<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AchievementResource extends JsonResource
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
            'nama_prestasi' => $this->nama_prestasi,
            'kategori' => $this->kategori,
            'tingkat' => $this->tingkat,
            'juara' => $this->juara,
            'nama_event' => $this->nama_event,
            'penyelenggara' => $this->penyelenggara,
            'tanggal_event' => $this->tanggal_event?->toDateString(),
            'tempat_event' => $this->tempat_event,
            'has_sertifikat' => (bool) $this->sertifikat_path,
            'has_foto' => (bool) $this->foto_kegiatan_path,
            'source' => $this->source,
            'status' => $this->status,
            'point_awarded' => $this->point_awarded,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'verified_at' => $this->verified_at,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
        ];
    }
}
