<?php

namespace App\Modules\Order\Http\Resources\Admin;

use App\Modules\Core\Enums\OrderStatus;
use App\Modules\Order\Http\Resources\Customer\OrderItemResource;
use App\Modules\Payment\Http\Resources\Admin\AdminPaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin.
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
        $account = $this->relationLoaded('account') ? $this->account : $this->account()->first();
        $userProfile = $account ? ($account->relationLoaded('userProfile') ? $account->userProfile : $account->userProfile()->first()) : null;

        return [
            'orderId' => (int) $this->orderId,
            'orderDate' => $orderDate,
            'status' => $statusName,
            'totalAmount' => (float) $this->totalAmount,
            'shippingAddress' => $this->shippingAddress,
            'accountId' => (int) $this->accountId,
            'customerName' => $userProfile?->fullName ?? $account?->username ?? 'Unknown',
            'customerPhone' => $userProfile?->phoneNumber,
            'payment' => $payment ? new AdminPaymentResource($payment) : null,
            'items' => OrderItemResource::collection($dishes),
        ];
    }
}

