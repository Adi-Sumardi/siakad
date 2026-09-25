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
            'scope' => $this->classroom_id ? 'classroom' : ($this->school_unit_id ? 'unit' : ($this->targets->where('kind', 'jenjang')->isNotEmpty() ? 'jenjang' : 'school')),
            'school_unit' => $this->whenLoaded('schoolUnit', fn () => $this->schoolUnit?->label),
            'classroom' => $this->whenLoaded('classroom', fn () => $this->classroom?->name),
            // The ladder keys + their display labels (Poin 8) - labels come
            // from the shared backend ladder so the UI badge and the stored
            // value can never drift apart.
            'jenjang_targets' => $this->whenLoaded('targets', fn () => $this->targets
                ->where('kind', 'jenjang')
                ->pluck('value')
                ->map(fn (string $key) => [
                    'key' => $key,
                    'label' => \App\Support\Jenjang::entry($key)['label']
                        ?? (collect(\App\Support\Jenjang::GROUPS)->first(fn ($g) => $g === $key) ? strtoupper($key) : $key),
                ])
                ->values()->all()),
            'file_name' => $this->file_name,
            'has_file' => (bool) $this->file_path,
            'is_pinned' => $this->is_pinned,
            'published_at' => $this->published_at,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
        ];
    }
}
