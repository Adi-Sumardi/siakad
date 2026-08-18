<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'title' => $this->title,
            'body' => $this->body,
            'scope' => $this->classroom_id ? 'classroom' : ($this->school_unit_id ? 'unit' : 'school'),
            'school_unit' => $this->whenLoaded('schoolUnit', fn () => $this->schoolUnit?->label),
            'classroom' => $this->whenLoaded('classroom', fn () => $this->classroom?->name),
            'file_name' => $this->file_name,
            'has_file' => (bool) $this->file_path,
            'is_pinned' => $this->is_pinned,
            'published_at' => $this->published_at,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
        ];
    }
}
