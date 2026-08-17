<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'payment_number' => $this->payment_number,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'channel' => $this->channel,
            'status' => $this->status,
            'invoice_url' => $this->invoice_url,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'bills' => BillResource::collection($this->whenLoaded('bills')),
        ];
    }
}
