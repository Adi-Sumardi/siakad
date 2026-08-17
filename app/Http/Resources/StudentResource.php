<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrollment = $this->currentEnrollment();

        return [
            'ulid' => $this->ulid,
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'nis' => $this->nis,
            'jenis_kelamin' => $this->jenis_kelamin,
            'status' => $this->status,
            'unit' => $this->whenLoaded('schoolUnit', fn () => [
                'code' => $this->schoolUnit->code,
                'label' => $this->schoolUnit->label,
            ]),
            'kelas' => $enrollment ? [
                'name' => $enrollment->classroom->name,
                'tingkat' => $enrollment->classroom->tingkat,
                'wali_kelas' => $enrollment->classroom->homeroomTeacher?->name,
            ] : null,
            // Fase 2 & 3 fill these in; the shape is here so the dashboard does
            // not change contract when they arrive.
            'tunggakan' => null,
            'poin' => null,
        ];
    }
}
