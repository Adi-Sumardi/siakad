<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'qty' => $this->qty,
            'unit_price' => (float) $this->unit_price,
            'amount' => (float) $this->amount,
            'size_option' => $this->size_option,
        ];
    }
}
