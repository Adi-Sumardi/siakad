<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vaData = null;
        if (! empty($this->gateway_response['va_number'])) {
            $vaData = [
                'va_number' => $this->gateway_response['va_number'],
                'bank_name' => $this->gateway_response['bank_name'] ?? 'Bank Muamalat',
                'bank_code' => $this->gateway_response['bank_code'] ?? '147',
                'amount' => (float) ($this->gateway_response['amount'] ?? $this->amount),
                'due_date' => $this->gateway_response['due_date'] ?? $this->expires_at?->toDateString(),
            ];
        }

        return [
            'ulid' => $this->ulid,
            'payment_number' => $this->payment_number,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'channel' => $this->channel,
            'status' => $this->status,
            'invoice_url' => $this->invoice_url,
            'virtual_account' => $vaData,
            'gateway_response' => $this->gateway_response,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'bills' => BillResource::collection($this->whenLoaded('bills')),
        ];
    }
}
