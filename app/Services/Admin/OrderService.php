<?php

namespace App\Services\Admin;

use App\Enums\CancellationMoneyDecision;
use App\Enums\DeliveryFailureReason;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\MoneySource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Shared\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Điều phối đơn giao/tự lấy (ADR-026): giao thất bại → giao lại hoặc hủy có quyết định tiền,
 * mã nhận hàng, sửa người nhận trước khi giao tài xế, tiền mặt chỉ ghi nhận khi thật sự thu.
 */
class OrderService
{
    /** Loại lý do hủy do nhà hàng chọn; quyết định tiền mặc định là phải hoàn. */
    public const CANCEL_REASON_TYPES = ['CUSTOMER_REQUEST', 'ADDRESS_UNSERVICEABLE', 'PICKUP_NO_SHOW', 'DELIVERY_FAILED', 'RESTAURANT_ISSUE', 'OTHER'];

    /** Trạng thái giao nhận còn cho sửa người nhận/địa chỉ (chưa giao tài xế, chưa bàn giao). */
    private const EDITABLE_FULFILLMENT = [FulfillmentStatus::PENDING, FulfillmentStatus::AWAITING_DRIVER, FulfillmentStatus::AWAITING_CUSTOMER, FulfillmentStatus::DELIVERY_FAILED];

    public function __construct(
        private readonly PaymentLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $filters */
    public function getOrders(array $filters = []): LengthAwarePaginator
    {
        $query = Order::query()->with(['account.userProfile', 'dishes', 'payment']);

        if (! empty($filters['status'])) {
            $status = $this->statusFromName((string) $filters['status']);
            $query->where('trang_thai', $status->value);
        }

        if (! empty($filters['channel'])) {
            $query->where('kenh', (string) $filters['channel']);
        }

        if (! empty($filters['fulfillmentType'])) {
            $type = FulfillmentType::tryFrom((string) $filters['fulfillmentType']);
            if ($type) {
                $query->where('loai_phuc_vu', $type->value);
            }
        }

        if (! empty($filters['fulfillmentStatus'])) {
            $fulfillment = FulfillmentStatus::tryFrom((string) $filters['fulfillmentStatus']);
            if ($fulfillment) {
                $query->where('trang_thai_giao_nhan', $fulfillment->value);
            }
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('ma_don_hang', ltrim($keyword, '#'))
                    ->orWhere('ten_nguoi_nhan', 'like', "%{$keyword}%")
                    ->orWhere('so_dien_thoai_nhan', 'like', "%{$keyword}%")
                    ->orWhereHas('account', function ($accountQuery) use ($keyword): void {
                        $accountQuery->where('ten_dang_nhap', 'like', "%{$keyword}%")
                            ->orWhereHas('userProfile', fn ($profileQuery) => $profileQuery->where('ho_ten', 'like', "%{$keyword}%"));
                    });
            });
        }

        return $query->orderByDesc('ngay_dat_hang')
            ->orderByDesc('ma_don_hang')
            ->paginate(min(max((int) ($filters['perPage'] ?? 15), 1), 50));
    }

    public function getOrder(int $orderId): Order
    {
        return Order::query()
            ->with(['account.userProfile', 'dishes', 'payment', 'cancelledBy.userProfile'])
            ->findOrFail($orderId);
    }

    public function updateStatus(int $orderId, string $targetStatus, ?Account $actor = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($orderId, $targetStatus, $actor, $reason): Order {
            $order = Order::query()->with('payment')->lockForUpdate()->findOrFail($orderId);
            $target = $this->statusFromName($targetStatus);
            $current = $order->trang_thai;

            $this->assertNotDineIn($order);

            if ($target === OrderStatus::CANCELLED) {
                throw new HttpException(422, 'Use the cancel action and record a reason.');
            }

            if (! $current->canTransitionTo($target)) {
                throw new HttpException(400, "Invalid status transition from {$current->name} to {$target->name}");
            }

            // Đơn hẹn: bắt đầu chế biến trước giờ chuẩn bị là ngoại lệ của quản lý, cần lý do và nhật ký.
            $startsEarly = in_array($target, [OrderStatus::PREPARING, OrderStatus::READY], true) && $order->awaitingKitchenRelease();
            $reason = $reason !== null ? trim($reason) : null;
            if ($startsEarly && ($reason === null || mb_strlen($reason) < 3)) {
                throw new HttpException(422, sprintf('This order is scheduled for %s. Give a reason to start it before %s.', $order->thoi_gian_nhan_du_kien->format('H:i'), $order->kitchenReleaseAt()->format('H:i')));
            }

            $updates = ['trang_thai' => $target];
            if ($target === OrderStatus::READY) {
                $updates['trang_thai_giao_nhan'] = $order->loai_phuc_vu->readyStatus();
                $updates['san_sang_luc'] = now();
            }

            $order->update($updates);
            $this->syncKitchenItems($order, $target);
            $this->audit->record($actor, $startsEarly ? 'ORDER_STARTED_EARLY' : 'ORDER_STATUS_CHANGED', 'ORDER', (int) $order->ma_don_hang, $reason, ['status' => $current->name], [
                'status' => $target->name,
                'scheduledAt' => $order->thoi_gian_nhan_du_kien?->toIso8601String(),
            ]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * @param  array{failureReason?: ?string, note?: ?string, pickupCode?: ?string, verifiedPhone?: ?string, collectedBy?: ?string}  $data
     */
    public function updateFulfillmentStatus(int $orderId, string $targetStatus, ?Account $actor = null, array $data = []): Order
    {
        return DB::transaction(function () use ($orderId, $targetStatus, $actor, $data): Order {
            $order = Order::query()->with('payment')->lockForUpdate()->findOrFail($orderId);
            $target = FulfillmentStatus::from($targetStatus);
            $type = $order->loai_phuc_vu;
            $current = $order->trang_thai_giao_nhan;

            if ($type === FulfillmentType::DINE_IN) {
                throw new HttpException(409, 'Dine-in fulfillment is managed by the POS service session');
            }

            if ($order->trang_thai !== OrderStatus::READY) {
                throw new HttpException(409, 'Order must be ready before handoff can be updated');
            }

            if (! $current->canTransitionTo($type, $target)) {
                throw new HttpException(400, "Invalid fulfillment transition from {$current->name} to {$target->name}");
            }

            $updates = ['trang_thai_giao_nhan' => $target];
            $reason = null;

            switch ($target) {
                case FulfillmentStatus::OUT_FOR_DELIVERY:
                    $this->assertQrPaymentCompleted($order, 'handing the order to the driver');
                    $updates['ban_giao_luc'] = now();
                    $updates['so_lan_giao'] = (int) $order->so_lan_giao + 1;
                    break;
                case FulfillmentStatus::DELIVERY_FAILED:
                    $failure = DeliveryFailureReason::tryFrom((string) ($data['failureReason'] ?? ''));
                    $note = trim((string) ($data['note'] ?? ''));
                    if (! $failure || mb_strlen($note) < 3) {
                        throw new HttpException(422, 'Choose why the delivery failed and describe what happened.');
                    }
                    $updates['loai_giao_that_bai'] = $failure->value;
                    $updates['ly_do_giao_that_bai'] = $note;
                    $updates['giao_that_bai_luc'] = now();
                    $reason = $failure->label().': '.$note;
                    break;
                case FulfillmentStatus::AWAITING_DRIVER:
                    $reason = trim((string) ($data['note'] ?? '')) ?: 'Giao lại';
                    break;
                case FulfillmentStatus::COLLECTED:
                    $updates = [...$updates, ...$this->verifyPickup($order, $data)];
                    $reason = $updates['nguoi_nhan_thay'] ? 'Nhận hộ: '.$updates['nguoi_nhan_thay'] : null;
                    break;
                default:
                    break;
            }

            if ($target->completesOrder()) {
                $this->settlePaymentForCompletion($order, $actor);
                $updates['trang_thai'] = OrderStatus::COMPLETED;
            }

            $before = ['fulfillmentStatus' => $current->name];
            $order->update($updates);
            if ($target->completesOrder()) {
                $this->syncKitchenItems($order, OrderStatus::COMPLETED);
            }
            $this->audit->record($actor, 'ORDER_FULFILLMENT_CHANGED', 'ORDER', (int) $order->ma_don_hang, $reason, $before, [
                'fulfillmentStatus' => $target->name,
                'deliveryAttempts' => (int) $order->so_lan_giao,
            ]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * Hủy đơn giao/tự lấy. Tiền đã thu mặc định là phải hoàn; giữ lại (RETAINED) chỉ khi lỗi
     * thuộc về khách (không đến lấy quá hạn, giao thất bại vì khách) và do ADMIN quyết định.
     *
     * @param  array{reasonType?: ?string, moneyDecision?: ?string}  $options
     */
    public function cancel(int $orderId, string $reason, ?Account $actor = null, array $options = []): Order
    {
        return DB::transaction(function () use ($orderId, $reason, $actor, $options): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            $this->assertNotDineIn($order);

            if (! $order->trang_thai->canBeCancelledByAdmin()) {
                throw new HttpException(409, 'This order can no longer be cancelled');
            }

            if ($order->trang_thai_giao_nhan === FulfillmentStatus::OUT_FOR_DELIVERY) {
                throw new HttpException(409, 'The driver is already delivering this order. Record the delivery result first.');
            }

            $reasonType = $options['reasonType'] ?? null;
            if ($reasonType !== null && ! in_array($reasonType, self::CANCEL_REASON_TYPES, true)) {
                throw new HttpException(422, 'Unknown cancellation reason type');
            }
            if ($reasonType === 'PICKUP_NO_SHOW' && ! $order->pickupOverdue()) {
                throw new HttpException(409, 'The pickup is not overdue yet.');
            }

            $paid = $this->ledger->paidAmount($order);
            $decision = null;
            if ($paid > 0) {
                $decision = CancellationMoneyDecision::tryFrom((string) ($options['moneyDecision'] ?? '')) ?? CancellationMoneyDecision::REFUND_DUE;
                if ($decision === CancellationMoneyDecision::RETAINED && ! $this->customerAtFault($order, $reasonType)) {
                    throw new HttpException(422, 'Money can only be kept when the customer did not collect an overdue pickup or refused/was unreachable for delivery.');
                }
            }

            $before = ['status' => $order->trang_thai->name, 'fulfillmentStatus' => $order->trang_thai_giao_nhan?->name];
            $order->update([
                'trang_thai' => OrderStatus::CANCELLED,
                'ly_do_huy' => $reason,
                'quyet_dinh_tien_huy' => $decision?->value,
                'ma_nguoi_huy' => $actor?->ma_tai_khoan,
                'huy_luc' => now(),
            ]);
            $this->syncKitchenItems($order, OrderStatus::CANCELLED);
            $this->ledger->syncOrderPayment($order->fresh());
            $this->audit->record($actor, 'ORDER_CANCELLED', 'ORDER', (int) $order->ma_don_hang, $reason, $before, [
                'status' => OrderStatus::CANCELLED->name,
                'reasonType' => $reasonType,
                'moneyDecision' => $decision?->value,
                'amountPaid' => $paid,
            ]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * Sửa người nhận/SĐT/địa chỉ trước khi giao tài xế hoặc bàn giao. Dùng chung cho khách và nhà hàng.
     *
     * @param  array{recipientName?: ?string, recipientPhone?: ?string, shippingAddress?: ?string}  $data
     */
    public function changeRecipient(Order $order, array $data, ?Account $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $data, $actor, $reason): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->ma_don_hang);
            $this->assertNotDineIn($order);

            if (in_array($order->trang_thai, [OrderStatus::CANCELLED, OrderStatus::COMPLETED], true)
                || ! in_array($order->trang_thai_giao_nhan, self::EDITABLE_FULFILLMENT, true)) {
                throw new HttpException(409, 'The order has already been handed over and can no longer be edited.');
            }

            $before = $this->recipientSnapshot($order);
            $updates = [];
            if (array_key_exists('recipientName', $data) && trim((string) $data['recipientName']) !== '') {
                $updates['ten_nguoi_nhan'] = trim((string) $data['recipientName']);
            }
            if (array_key_exists('recipientPhone', $data) && trim((string) $data['recipientPhone']) !== '') {
                $updates['so_dien_thoai_nhan'] = trim((string) $data['recipientPhone']);
            }
            if (array_key_exists('shippingAddress', $data)) {
                if ($order->loai_phuc_vu !== FulfillmentType::DELIVERY) {
                    throw new HttpException(422, 'Only delivery orders have a shipping address.');
                }
                $address = trim((string) $data['shippingAddress']);
                if ($address === '') {
                    throw new HttpException(422, 'Shipping address is required for delivery.');
                }
                $updates['dia_chi_giao_hang'] = $address;
            }

            if ($updates === []) {
                throw new HttpException(422, 'Nothing to change.');
            }

            $order->update($updates);
            $this->audit->record($actor, 'ORDER_RECIPIENT_CHANGED', 'ORDER', (int) $order->ma_don_hang, $reason, $before, $this->recipientSnapshot($order->fresh()));

            return $order->fresh(['account.userProfile', 'dishes', 'payment']);
        });
    }

    private function assertNotDineIn(Order $order): void
    {
        if ($order->loai_phuc_vu === FulfillmentType::DINE_IN) {
            throw new HttpException(409, 'Dine-in orders are managed by the POS session and kitchen board');
        }
    }

    /**
     * Bàn giao cho khách tự lấy: đúng mã nhận hàng, hoặc đúng SĐT đặt đơn (đối chiếu mã đơn + liên hệ).
     * Người lấy hộ chỉ được ghi nhận sau khi xác minh.
     *
     * @param  array{pickupCode?: ?string, verifiedPhone?: ?string, collectedBy?: ?string}  $data
     * @return array{nguoi_nhan_thay: ?string, ban_giao_luc: Carbon}
     */
    private function verifyPickup(Order $order, array $data): array
    {
        $code = trim((string) ($data['pickupCode'] ?? ''));
        $phone = $this->normalizePhone((string) ($data['verifiedPhone'] ?? ''));

        $codeMatches = $code !== '' && $order->ma_nhan_hang && hash_equals((string) $order->ma_nhan_hang, $code);
        $phoneMatches = $phone !== '' && $phone === $this->normalizePhone((string) $order->so_dien_thoai_nhan);

        if (! $codeMatches && ! $phoneMatches) {
            throw new HttpException(422, $code !== '' || $phone !== ''
                ? 'The pickup code or phone number does not match this order.'
                : 'Enter the pickup code or the phone number on the order before handing it over.');
        }

        $collectedBy = trim((string) ($data['collectedBy'] ?? ''));

        return [
            'nguoi_nhan_thay' => $collectedBy !== '' ? mb_substr($collectedBy, 0, 100) : null,
            'ban_giao_luc' => now(),
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '84') && strlen($digits) > 9 ? '0'.substr($digits, 2) : $digits;
    }

    private function customerAtFault(Order $order, ?string $reasonType): bool
    {
        if ($order->loai_phuc_vu === FulfillmentType::PICKUP) {
            return $reasonType === 'PICKUP_NO_SHOW' && $order->pickupOverdue();
        }

        return $order->trang_thai_giao_nhan === FulfillmentStatus::DELIVERY_FAILED
            && in_array($order->loai_giao_that_bai, [DeliveryFailureReason::CUSTOMER_REFUSED->value, DeliveryFailureReason::CUSTOMER_UNREACHABLE->value], true);
    }

    /** @return array<string, ?string> */
    private function recipientSnapshot(Order $order): array
    {
        return [
            'recipientName' => $order->ten_nguoi_nhan,
            'recipientPhone' => $order->so_dien_thoai_nhan,
            'shippingAddress' => $order->dia_chi_giao_hang,
        ];
    }

    /**
     * Mirror an order-level transition onto its kitchen items so the KDS stays truthful.
     */
    private function syncKitchenItems(Order $order, OrderStatus $orderStatus): void
    {
        $transitions = match ($orderStatus) {
            OrderStatus::PREPARING => [[KitchenItemStatus::SENT, KitchenItemStatus::QUEUED], KitchenItemStatus::PREPARING],
            OrderStatus::READY => [[KitchenItemStatus::SENT, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING], KitchenItemStatus::READY],
            OrderStatus::COMPLETED => [[KitchenItemStatus::SENT, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY], KitchenItemStatus::SERVED],
            OrderStatus::CANCELLED => [[KitchenItemStatus::DRAFT, KitchenItemStatus::SENT, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY], KitchenItemStatus::CANCELLED],
            default => null,
        };

        if (! $transitions) {
            return;
        }

        [$fromStatuses, $toStatus] = $transitions;
        $updates = ['trang_thai_bep' => $toStatus->value, 'bep_cap_nhat_luc' => now()];
        if ($toStatus === KitchenItemStatus::CANCELLED) {
            $updates['so_luong_tinh_tien'] = 0;
            $updates['huy_luc'] = now();
        }

        OrderItem::where('ma_don_hang', $order->ma_don_hang)
            ->whereIn('trang_thai_bep', array_map(fn (KitchenItemStatus $status) => $status->value, $fromStatuses))
            ->update($updates);
    }

    private function statusFromName(string $name): OrderStatus
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status->name === $name || $status->value === $name) {
                return $status;
            }
        }

        throw new HttpException(422, 'Unknown order status');
    }

    /**
     * Tiền mặt chỉ được ghi nhận khi bàn giao thành công (tài xế/quầy thu thật). Giao thất bại
     * không bao giờ tạo bút toán tiền mặt.
     */
    private function settlePaymentForCompletion(Order $order, ?Account $actor): void
    {
        if (! $order->payment) {
            throw new HttpException(409, 'Order has no payment record');
        }

        $this->assertQrPaymentCompleted($order, 'completing the order');

        $outstanding = $this->ledger->outstanding($order);
        if ($order->payment->phuong_thuc !== PaymentMethod::CASH || $outstanding <= $this->ledger->tolerance()) {
            return;
        }

        $attempt = max(1, (int) $order->so_lan_giao);
        $recorded = $this->ledger->record([
            'source' => MoneySource::COD,
            'method' => PaymentMethod::CASH,
            'amount' => $outstanding,
            'provider' => $order->loai_phuc_vu === FulfillmentType::DELIVERY ? 'CASH_ON_DELIVERY' : 'CASH_AT_PICKUP',
            'idempotencyKey' => 'cod-'.$order->ma_don_hang.'-'.$attempt,
            'orderId' => $order->ma_don_hang,
            'recordedBy' => $actor,
        ]);
        $this->ledger->applyToOrder($recorded['transaction'], $order, null, $actor);
    }

    private function assertQrPaymentCompleted(Order $order, string $action): void
    {
        if (! $order->payment) {
            throw new HttpException(409, 'Order has no payment record');
        }

        if ($order->payment->phuong_thuc === PaymentMethod::BANK_TRANSFER_QR
            && $this->ledger->outstanding($order) > $this->ledger->tolerance()) {
            throw new HttpException(400, "QR bank transfer must be paid before {$action}");
        }
    }
}
