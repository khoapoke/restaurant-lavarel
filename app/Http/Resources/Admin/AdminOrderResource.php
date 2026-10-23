<?php

namespace App\Http\Resources\Admin;

use App\Enums\DeliveryFailureReason;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
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
            'isAsap' => ! $this->thoi_gian_nhan_du_kien,
            'kitchenReleaseAt' => $this->kitchenReleaseAt()?->toIso8601String(),
            'awaitingKitchenRelease' => $this->awaitingKitchenRelease(),
            'note' => $this->ghi_chu,
            // Mã nhận hàng không trả cho màn quản trị: nhân viên nhập mã khách đọc để đối chiếu.
            'hasPickupCode' => (bool) $this->ma_nhan_hang,
            'readyAt' => $this->san_sang_luc?->toIso8601String(),
            'handedOverAt' => $this->ban_giao_luc?->toIso8601String(),
            'collectedBy' => $this->nguoi_nhan_thay,
            'deliveryAttempts' => (int) $this->so_lan_giao,
            'deliveryFailure' => $this->giao_that_bai_luc ? [
                'reason' => $this->loai_giao_that_bai,
                'reasonLabel' => DeliveryFailureReason::tryFrom((string) $this->loai_giao_that_bai)?->label(),
                'note' => $this->ly_do_giao_that_bai,
                'at' => $this->giao_that_bai_luc->toIso8601String(),
            ] : null,
            'cancellation' => $this->trang_thai === OrderStatus::CANCELLED ? [
                'reason' => $this->ly_do_huy,
                'moneyDecision' => $this->quyet_dinh_tien_huy,
                'at' => $this->huy_luc?->toIso8601String(),
                'by' => $this->relationLoaded('cancelledBy') ? $this->cancelledBy?->displayName() : null,
            ] : null,
            'handoff' => [
                'waitMinutes' => $this->handoffWaitMinutes(),
                'pickupDeadline' => $this->pickupDeadline()?->toIso8601String(),
                'pickupOverdue' => $this->pickupOverdue(),
                'driverWaitTooLong' => $this->driverWaitTooLong(),
            ],
            'money' => $this->whenLoaded('payment', fn (): array => $this->moneySummary()),
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
     * @return array<string, mixed>
     */
    private function availableActions(): array
    {
        $isDineIn = $this->loai_phuc_vu === FulfillmentType::DINE_IN;
        $money = $this->moneySummary();
        $isQrUnpaid = $this->payment
            && $this->payment->phuong_thuc === PaymentMethod::BANK_TRANSFER_QR
            && $money['outstanding'] > 0;
        $ready = ! $isDineIn && $this->trang_thai === OrderStatus::READY;

        $nextStatus = $isDineIn ? null : match ($this->trang_thai) {
            OrderStatus::PENDING_CONFIRMATION => OrderStatus::CONFIRMED,
            OrderStatus::CONFIRMED => OrderStatus::PREPARING,
            OrderStatus::PREPARING => OrderStatus::READY,
            default => null,
        };

        $nextFulfillment = $ready
            ? match ($this->trang_thai_giao_nhan) {
                FulfillmentStatus::AWAITING_DRIVER => FulfillmentStatus::OUT_FOR_DELIVERY,
                FulfillmentStatus::OUT_FOR_DELIVERY => FulfillmentStatus::DELIVERED,
                FulfillmentStatus::AWAITING_CUSTOMER => FulfillmentStatus::COLLECTED,
                FulfillmentStatus::DELIVERY_FAILED => FulfillmentStatus::AWAITING_DRIVER,
                default => null,
            }
        : null;

        $customerFault = $this->loai_phuc_vu === FulfillmentType::PICKUP
            ? $this->pickupOverdue()
            : ($this->trang_thai_giao_nhan === FulfillmentStatus::DELIVERY_FAILED
                && in_array($this->loai_giao_that_bai, [DeliveryFailureReason::CUSTOMER_REFUSED->value, DeliveryFailureReason::CUSTOMER_UNREACHABLE->value], true));

        $canCancel = ! $isDineIn
            && $this->trang_thai->canBeCancelledByAdmin()
            && $this->trang_thai_giao_nhan !== FulfillmentStatus::OUT_FOR_DELIVERY;

        return [
            'nextStatus' => $nextStatus?->name,
            // Chuyển bếp trước giờ chuẩn bị của đơn hẹn: chỉ quản lý, bắt buộc lý do.
            'startRequiresReason' => $nextStatus === OrderStatus::PREPARING && $this->awaitingKitchenRelease(),
            'nextFulfillmentStatus' => $nextFulfillment?->name,
            'canRecordDeliveryFailure' => $ready && $this->trang_thai_giao_nhan === FulfillmentStatus::OUT_FOR_DELIVERY,
            'canRedeliver' => $ready && $this->trang_thai_giao_nhan === FulfillmentStatus::DELIVERY_FAILED,
            'requiresPickupVerification' => $ready && $this->trang_thai_giao_nhan === FulfillmentStatus::AWAITING_CUSTOMER,
            'canEditRecipient' => $this->recipientEditable(),
            'canCancel' => $canCancel,
            'canMarkPickupNoShow' => $canCancel && $this->pickupOverdue(),
            'canRetainMoney' => $canCancel && $customerFault && $money['amountPaid'] > 0,
            'awaitingQrPayment' => (bool) $isQrUnpaid && $this->trang_thai !== OrderStatus::CANCELLED,
            'requiresRefund' => $money['refundDue'] > 0,
        ];
    }
}
