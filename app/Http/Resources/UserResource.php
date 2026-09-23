<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            // The profile page renders this next to email - it is the owner's
            // own contact data, same sensitivity as email already above.
            'phone' => $this->phone,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'activated_at' => $this->activated_at,
            'school_unit' => $this->whenLoaded('schoolUnit', fn () => [
                'ulid' => $this->schoolUnit->ulid,
                'code' => $this->schoolUnit->code,
                'label' => $this->schoolUnit->label,
            ]),
        ];
    }
}
