<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quantity = (int) ($this->pivot?->so_luong ?? 0);
        $unitPrice = (float) $this->price;
        $subTotal = round($unitPrice * $quantity, 2);
        $isAvailable = (bool) ($this->isAvailable && $this->isVisible && !$this->isDeleted);

        return [
            'dishId' => (int) $this->dishId,
            'dishName' => $this->dishName,
            'imageUrl' => $this->imageUrl,
            'unitPrice' => $unitPrice,
            'quantity' => $quantity,
            'subTotal' => $subTotal,
            'isAvailable' => $isAvailable,
        ];
    }
}
