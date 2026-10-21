<?php

namespace App\Http\Resources\Admin;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Resources\Customer\OrderItemResource;
use App\Http\Resources\Customer\OrderPaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->trang_thai instanceof OrderStatus ? $this->trang_thai->name : (string) $this->trang_thai;
        $channel = $this->kenh instanceof OrderChannel ? $this->kenh->name : (string) $this->kenh;
        $fulfillmentType = $this->loai_phuc_vu instanceof FulfillmentType ? $this->loai_phuc_vu->name : (string) $this->loai_phuc_vu;
        $fulfillmentStatus = $this->trang_thai_giao_nhan instanceof FulfillmentStatus ? $this->trang_thai_giao_nhan->name : (string) $this->trang_thai_giao_nhan;

        return [
            'orderId' => (int) $this->ma_don_hang,
            'orderDate' => $this->ngay_dat_hang?->format('Y-m-d\TH:i:s'),
            'status' => $status,
            'channel' => $channel,
            'fulfillmentType' => $fulfillmentType,
            'fulfillmentStatus' => $fulfillmentStatus,
            'totalAmount' => (float) $this->tong_tien,
            'deliveryFee' => (float) $this->phi_giao_hang,
            'recipientName' => $this->ten_nguoi_nhan,
            'recipientPhone' => $this->so_dien_thoai_nhan,
            'shippingAddress' => $this->dia_chi_giao_hang,
            'scheduledAt' => $this->thoi_gian_nhan_du_kien?->format('Y-m-d\TH:i:s'),
            'note' => $this->ghi_chu,
            'customer' => $this->whenLoaded('account', fn (): array => [
                'accountId' => (int) $this->account->ma_tai_khoan,
                'username' => $this->account->ten_dang_nhap,
                'fullName' => $this->account->userProfile?->ho_ten,
                'phoneNumber' => $this->account->userProfile?->so_dien_thoai,
            ]),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? new OrderPaymentResource($this->payment) : null),
            'items' => $this->whenLoaded('dishes', fn () => OrderItemResource::collection($this->dishes)),
            'availableActions' => $this->whenLoaded('payment', fn (): array => $this->availableActions()),
        ];
    }

    /**
     * @return array{nextStatus: ?string, nextFulfillmentStatus: ?string, canCancel: bool, awaitingQrPayment: bool, requiresRefund: bool}
     */
    private function availableActions(): array
    {
        $isDineIn = $this->loai_phuc_vu === FulfillmentType::DINE_IN;
        $isQrUnpaid = $this->payment
            && $this->payment->phuong_thuc === PaymentMethod::BANK_TRANSFER_QR
            && $this->payment->trang_thai !== PaymentStatus::PAID;

        $nextStatus = $isDineIn ? null : match ($this->trang_thai) {
            OrderStatus::PENDING_CONFIRMATION => OrderStatus::CONFIRMED,
            OrderStatus::CONFIRMED => OrderStatus::PREPARING,
            OrderStatus::PREPARING => OrderStatus::READY,
            default => null,
        };

        $nextFulfillment = (! $isDineIn && $this->trang_thai === OrderStatus::READY)
            ? match ($this->trang_thai_giao_nhan) {
                FulfillmentStatus::AWAITING_DRIVER => FulfillmentStatus::OUT_FOR_DELIVERY,
                FulfillmentStatus::OUT_FOR_DELIVERY => FulfillmentStatus::DELIVERED,
                FulfillmentStatus::AWAITING_CUSTOMER => FulfillmentStatus::COLLECTED,
                default => null,
            }
        : null;

        return [
            'nextStatus' => $nextStatus?->name,
            'nextFulfillmentStatus' => $nextFulfillment?->name,
            'canCancel' => ! $isDineIn
                && $this->trang_thai->canBeCancelledByAdmin()
                && $this->trang_thai_giao_nhan !== FulfillmentStatus::OUT_FOR_DELIVERY,
            'awaitingQrPayment' => (bool) $isQrUnpaid && $this->trang_thai !== OrderStatus::CANCELLED,
            'requiresRefund' => $this->trang_thai === OrderStatus::CANCELLED
                && $this->payment?->trang_thai === PaymentStatus::PAID,
        ];
    }
}
