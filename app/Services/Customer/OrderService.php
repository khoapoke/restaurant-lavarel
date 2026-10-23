<?php

namespace App\Services\Customer;

use App\Enums\CancellationMoneyDecision;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Account;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Admin\OrderService as AdminOrderService;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\OpeningHours;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderService
{
    public function __construct(
        private readonly OpeningHours $hours,
        private readonly AdminOrderService $orders,
        private readonly PaymentLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Checkout active cart to create order (ORD-01, ORD-02, ORD-03).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws HttpException
     */
    public function checkout(int $accountId, array $data): Order
    {
        return DB::transaction(function () use ($accountId, $data) {
            // STEP 1: Khóa bản ghi Giỏ hàng để chống Race Condition (Double-click Checkout)
            $cart = Cart::with('dishes')
                ->where('ma_tai_khoan', $accountId)
                ->lockForUpdate()
                ->first();

            if (! $cart || $cart->dishes->isEmpty()) {
                throw new HttpException(400, 'Cannot checkout an empty cart');
            }

            // STEP 2: Kiểm tra lại trạng thái từng món & Tính tổng tiền kèm Snapshot Đơn giá
            $totalAmount = 0.0;
            $orderPivotData = [];

            foreach ($cart->dishes as $dish) {
                if (! $dish->isOrderable()) {
                    throw new HttpException(400, "Dish '{$dish->dishName}' has just sold out");
                }

                $qty = (int) $dish->pivot->so_luong;
                if ($qty <= 0) {
                    continue;
                }

                $unitPrice = (float) $dish->gia_tien; // Chốt cứng đơn giá tại thời điểm đặt
                $totalAmount += ($unitPrice * $qty);

                $orderPivotData[$dish->ma_mon_an] = [
                    'ten_mon_snapshot' => $dish->ten_mon_an,
                    'so_luong' => $qty,
                    'so_luong_tinh_tien' => $qty,
                    'don_gia' => $unitPrice,
                    'trang_thai_bep' => KitchenItemStatus::SENT->value,
                ];
            }

            if (empty($orderPivotData)) {
                throw new HttpException(400, 'Cannot checkout an empty cart');
            }

            // Giá đã đổi so với số khách đang xem: không tạo đơn, giỏ giữ nguyên để khách xác nhận lại.
            if (isset($data['expectedTotal']) && abs((float) $data['expectedTotal'] - $totalAmount) > 0.009) {
                throw new DomainConflictException('Prices changed since you last viewed the cart. Review the new total and confirm again.', [
                    'reason' => 'PRICE_CHANGED',
                    'expectedTotal' => round((float) $data['expectedTotal'], 2),
                    'currentTotal' => round($totalAmount, 2),
                ]);
            }

            $fulfillmentType = FulfillmentType::from($data['fulfillmentType']);
            $scheduledAt = $this->validateSchedule($data['scheduledAt'] ?? null);
            $recipientName = trim($data['recipientName'] ?? '');
            $recipientPhone = trim($data['recipientPhone'] ?? '');
            $orderNote = trim($data['orderNote'] ?? '');
            $shippingAddress = $fulfillmentType === FulfillmentType::DELIVERY
                ? trim($data['shippingAddress'] ?? '')
                : null;
            $deliveryFee = 0.0;
            $grandTotal = $totalAmount + $deliveryFee;

            // STEP 4: Tạo bản ghi DON_HANG
            $order = Order::create([
                'ngay_dat_hang' => now(),
                'tong_tien' => $grandTotal,
                'phi_giao_hang' => $deliveryFee,
                'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
                'dia_chi_giao_hang' => $shippingAddress,
                'ten_nguoi_nhan' => $recipientName,
                'so_dien_thoai_nhan' => $recipientPhone,
                'thoi_gian_nhan_du_kien' => $scheduledAt,
                'ma_tai_khoan' => $accountId,
                'kenh' => OrderChannel::ONLINE,
                'loai_phuc_vu' => $fulfillmentType,
                'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
                'ghi_chu' => $orderNote ?: null,
            ]);

            // STEP 5: Chèn toàn bộ chi tiết món vào CHI_TIET_DON_HANG qua hàm attach()
            $order->dishes()->attach($orderPivotData);

            // STEP 6: Khởi tạo bản ghi THANH_TOAN (1-1 với DON_HANG) (ORD-03)
            $paymentMethodInput = $data['paymentMethod'] ?? 'CASH';
            $paymentMethod = match ($paymentMethodInput) {
                'BANK_TRANSFER_QR', PaymentMethod::BANK_TRANSFER_QR->value => PaymentMethod::BANK_TRANSFER_QR,
                'CASH', PaymentMethod::CASH->value => PaymentMethod::CASH,
                default => PaymentMethod::CASH,
            };

            $transactionCode = null;
            if ($paymentMethod === PaymentMethod::CASH) {
                $transactionCode = 'CASH-'.$order->ma_don_hang;
            }

            Payment::create([
                'so_tien' => $grandTotal,
                'phuong_thuc' => $paymentMethod,
                'trang_thai' => PaymentStatus::UNPAID,
                'ma_don_hang' => $order->ma_don_hang,
                'ma_giao_dich' => $transactionCode,
            ]);

            // STEP 7: Làm rỗng bảng MON_AN_GIO_HANG của khách
            $cart->dishes()->detach();

            return $order->load(['dishes', 'payment']);
        });
    }

    /**
     * Giờ hẹn: trong giờ nhận gọi món của ngày đó, tối đa N ngày tới. Không hẹn (ASAP) chỉ
     * khi bếp đang nhận đơn.
     */
    private function validateSchedule(mixed $scheduledAt): ?Carbon
    {
        if ($scheduledAt === null || $scheduledAt === '') {
            $this->hours->assertAcceptsOrders();

            return null;
        }

        $moment = Carbon::parse($scheduledAt)->setTimezone(config('app.timezone'));
        if ($moment->lessThanOrEqualTo(now())) {
            throw new HttpException(422, 'Scheduled time must be in the future.');
        }
        if ($moment->greaterThan(now()->addDays((int) config('restaurant.fulfillment.max_schedule_days', 7)))) {
            throw new HttpException(422, sprintf('Orders can be scheduled at most %d days ahead.', (int) config('restaurant.fulfillment.max_schedule_days', 7)));
        }
        if (! $this->hours->acceptsOrdersAt($moment)) {
            throw new HttpException(422, sprintf('Choose a time between %s and %s.', config('restaurant.hours.opens_at'), config('restaurant.hours.last_order_at')));
        }

        return $moment;
    }

    /**
     * Khách sửa người nhận/SĐT/địa chỉ trước khi đơn được giao tài xế hoặc bàn giao.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRecipient(Account $account, int $orderId, array $data): Order
    {
        $order = Order::query()
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $account->ma_tai_khoan)
            ->first();
        if (! $order) {
            throw new HttpException(404, 'Order not found');
        }

        return $this->orders->changeRecipient($order, $data, $account, 'Khách tự cập nhật');
    }

    /**
     * Get paginated customer order history with status filter (ORD-04).
     *
     * @param  array<string, mixed>  $params
     */
    public function getCustomerOrders(int $accountId, array $params = []): LengthAwarePaginator
    {
        $query = Order::with(['dishes', 'payment'])
            ->where('ma_tai_khoan', $accountId);

        // Filter by status if provided (support English enum key or Vietnamese value)
        if (! empty($params['status'])) {
            $statusInput = trim($params['status']);
            $matchedStatus = null;
            foreach (OrderStatus::cases() as $case) {
                if ($case->name === $statusInput || $case->value === $statusInput) {
                    $matchedStatus = $case;
                    break;
                }
            }
            if ($matchedStatus) {
                $query->where('trang_thai', $matchedStatus->value);
            }
        }

        $query->orderByDesc('ma_don_hang');

        $perPage = min(max((int) ($params['perPage'] ?? 10), 1), 50);

        return $query->paginate($perPage);
    }

    /**
     * Get detailed order by ID belonging to customer (ORD-05).
     *
     * @throws HttpException
     */
    public function getCustomerOrderDetail(int $accountId, int $orderId): Order
    {
        $order = Order::with(['dishes', 'payment'])
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (! $order) {
            throw new HttpException(404, 'Order not found');
        }

        return $order;
    }

    /**
     * Customer cancels order (ORD-06). Only allowed when status is PENDING_CONFIRMATION.
     *
     * @throws HttpException
     */
    public function cancelCustomerOrder(int $accountId, int $orderId, ?string $cancelReason = null): Order
    {
        return DB::transaction(function () use ($accountId, $orderId, $cancelReason): Order {
            $order = Order::query()
                ->where('ma_don_hang', $orderId)
                ->where('ma_tai_khoan', $accountId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new HttpException(404, 'Order not found');
            }

            // Quy tắc: Khách hàng chỉ có thể hủy đơn khi đơn hàng đang ở trạng thái PENDING_CONFIRMATION
            if ($order->trang_thai !== OrderStatus::PENDING_CONFIRMATION) {
                throw new HttpException(409, 'Order cannot be cancelled because it is already being prepared by the kitchen');
            }

            $reason = trim((string) $cancelReason);
            $paid = $this->ledger->paidAmount($order);
            $order->forceFill([
                'trang_thai' => OrderStatus::CANCELLED,
                'ly_do_huy' => $reason !== '' ? $reason : 'Khách tự hủy',
                'quyet_dinh_tien_huy' => $paid > 0 ? CancellationMoneyDecision::REFUND_DUE->value : null,
                'ma_nguoi_huy' => $accountId,
                'huy_luc' => now(),
            ])->save();

            OrderItem::where('ma_don_hang', $order->ma_don_hang)
                ->where('trang_thai_bep', '!=', KitchenItemStatus::SERVED->value)
                ->update(['trang_thai_bep' => KitchenItemStatus::CANCELLED->value, 'so_luong_tinh_tien' => 0, 'huy_luc' => now()]);

            $payment = Payment::query()->where('ma_don_hang', $order->ma_don_hang)->lockForUpdate()->first();
            if ($payment && $reason !== '' && empty($payment->ly_do_hoan_tien)) {
                $payment->ly_do_hoan_tien = $reason;
                $payment->save();
            }
            $this->ledger->syncOrderPayment($order->fresh());
            $this->audit->record(Account::find($accountId), 'ORDER_CANCELLED', 'ORDER', (int) $order->ma_don_hang, $order->ly_do_huy, ['status' => OrderStatus::PENDING_CONFIRMATION->name], [
                'status' => OrderStatus::CANCELLED->name,
                'reasonType' => 'CUSTOMER_REQUEST',
                'moneyDecision' => $order->quyet_dinh_tien_huy,
            ]);

            return $order->load('payment');
        });
    }
}
