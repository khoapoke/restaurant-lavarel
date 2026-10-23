<?php

namespace App\Http\Resources\Customer;

use App\Enums\DeliveryFailureReason;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
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
        $fulfillmentType = $this->loai_phuc_vu instanceof FulfillmentType
            ? $this->loai_phuc_vu->name
            : (string) $this->loai_phuc_vu;
        $fulfillmentStatus = $this->trang_thai_giao_nhan instanceof FulfillmentStatus
            ? $this->trang_thai_giao_nhan->name
            : (string) $this->trang_thai_giao_nhan;

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
            'fulfillmentType' => $fulfillmentType,
            'fulfillmentStatus' => $fulfillmentStatus,
            'totalAmount' => (float) $this->totalAmount,
            'deliveryFee' => (float) $this->phi_giao_hang,
            'itemsSubtotal' => (float) $this->totalAmount - (float) $this->phi_giao_hang,
            'recipientName' => $this->ten_nguoi_nhan,
            'recipientPhone' => $this->so_dien_thoai_nhan,
            'shippingAddress' => $this->shippingAddress,
            'scheduledAt' => $this->thoi_gian_nhan_du_kien?->format('Y-m-d\TH:i:s'),
            'orderNote' => $this->ghi_chu,
            'isAsap' => ! $this->thoi_gian_nhan_du_kien,
            'pickupCode' => $fulfillmentType === FulfillmentType::PICKUP->name ? $this->ma_nhan_hang : null,
            'readyAt' => $this->san_sang_luc?->toIso8601String(),
            'handedOverAt' => $this->ban_giao_luc?->toIso8601String(),
            'deliveryAttempts' => (int) $this->so_lan_giao,
            'deliveryFailure' => $this->giao_that_bai_luc ? [
                'reason' => $this->loai_giao_that_bai,
                'reasonLabel' => DeliveryFailureReason::tryFrom((string) $this->loai_giao_that_bai)?->label(),
                'at' => $this->giao_that_bai_luc->toIso8601String(),
            ] : null,
            'cancellation' => $statusName === OrderStatus::CANCELLED->name ? [
                'reason' => $this->ly_do_huy,
                'at' => $this->huy_luc?->toIso8601String(),
                'moneyDecision' => $this->quyet_dinh_tien_huy,
            ] : null,
            'money' => $this->moneySummary(),
            'canEditRecipient' => $this->recipientEditable(),
            'payment' => $payment ? new OrderPaymentResource($payment) : null,
            'items' => OrderItemResource::collection($dishes),
        ];
    }
}
