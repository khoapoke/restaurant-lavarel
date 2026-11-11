<?php

namespace App\Modules\Cart\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dishes = $this->dishes ?? collect();

        $totalQuantity = 0;
        $estimatedTotalAmount = 0.0;

        foreach ($dishes as $dish) {
            $qty = (int) ($dish->pivot?->so_luong ?? 0);
            $price = (float) $dish->price;
            $totalQuantity += $qty;
            $estimatedTotalAmount += ($price * $qty);
        }

        return [
            'cartId' => (int) $this->cartId,
            'accountId' => (int) $this->accountId,
            'items' => CartItemResource::collection($dishes),
            'totalQuantity' => $totalQuantity,
            'estimatedTotalAmount' => round($estimatedTotalAmount, 2),
        ];
    }
}

