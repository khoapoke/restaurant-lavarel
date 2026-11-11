<?php

namespace App\Modules\Order\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quantity = (int) ($this->pivot?->so_luong ?? $this->so_luong ?? 0);
        $unitPrice = (float) ($this->pivot?->don_gia ?? $this->don_gia ?? $this->price ?? 0);
        $subTotal = round($quantity * $unitPrice, 2);

        return [
            'dishId' => (int) ($this->dishId ?? $this->ma_mon_an),
            'dishName' => $this->dishName ?? $this->ten_mon_an,
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'subTotal' => $subTotal,
        ];
    }
}

