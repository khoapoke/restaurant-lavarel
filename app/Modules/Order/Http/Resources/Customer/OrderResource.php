<?php

namespace App\Modules\Order\Http\Resources\Customer;

use App\Modules\Core\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusName = $this->status instanceof OrderStatus
            ? $this->status->name
            : (OrderStatus::tryFrom($this->status)?->name ?? (string) $this->status);

        $orderDate = null;
        if ($this->ngay_dat_hang) {
            $orderDate = is_string($this->ngay_dat_hang)
                ? $this->ngay_dat_hang
                : $this->ngay_dat_hang->format('Y-m-d\TH:i:s');
        }

        $payment = $this->relationLoaded('payment') ? $this->payment : $this->payment()->first();
        $dishes = $this->relationLoaded('dishes') ? $this->dishes : $this->dishes()->get();

        return [
            'orderId' => (int) $this->orderId,
            'orderDate' => $orderDate,
            'status' => $statusName,
            'totalAmount' => (float) $this->totalAmount,
            'shippingAddress' => $this->shippingAddress,
            'payment' => $payment ? new OrderPaymentResource($payment) : null,
            'items' => OrderItemResource::collection($dishes),
        ];
    }
}

