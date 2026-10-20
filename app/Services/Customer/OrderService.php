<?php

namespace App\Services\Customer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use \Illuminate\Contracts\Pagination\LengthAwarePaginator;


class OrderService
{
    /**
     * Checkout active cart to create order (ORD-01, ORD-02, ORD-03).
     *
     * @param int $accountId
     * @param array<string, mixed> $data
     * @return Order
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

            if (!$cart || $cart->dishes->isEmpty()) {
                throw new HttpException(400, 'Cannot checkout an empty cart');
            }

            // STEP 2: Kiểm tra lại trạng thái từng món & Tính tổng tiền kèm Snapshot Đơn giá
            $totalAmount = 0.0;
            $orderPivotData = [];

            foreach ($cart->dishes as $dish) {
                if (!$dish->isAvailable || !$dish->isVisible || $dish->isDeleted) {
                    throw new HttpException(400, "Dish '{$dish->dishName}' has just sold out");
                }

                $qty = (int) $dish->pivot->so_luong;
                if ($qty <= 0) {
                    continue;
                }

                $unitPrice = (float) $dish->gia_tien; // Chốt cứng đơn giá tại thời điểm đặt
                $totalAmount += ($unitPrice * $qty);

                $orderPivotData[$dish->ma_mon_an] = [
                    'so_luong' => $qty,
                    'don_gia' => $unitPrice,
                ];
            }

            if (empty($orderPivotData)) {
                throw new HttpException(400, 'Cannot checkout an empty cart');
            }

            // STEP 3: Chuẩn hóa chuỗi địa chỉ nhận hàng theo Hình thức nhận (ORD-01, ORD-02)
            $fulfillmentType = $data['fulfillmentType'] ?? 'DELIVERY';
            $recipientName = trim($data['recipientName'] ?? '');
            $recipientPhone = trim($data['recipientPhone'] ?? '');
            $orderNote = trim($data['orderNote'] ?? '');

            if ($fulfillmentType === 'PICKUP') {
                $shippingAddress = "[PICKUP] Nhận tại nhà hàng - KH: {$recipientName} - SĐT: {$recipientPhone}";
            } else {
                $address = trim($data['shippingAddress'] ?? '');
                $shippingAddress = "[DELIVERY] {$recipientName} - {$recipientPhone} | {$address}";
            }

            if (!empty($orderNote)) {
                $shippingAddress .= " | Note: {$orderNote}";
            }

            // STEP 4: Tạo bản ghi DON_HANG
            $order = Order::create([
                'ngay_dat_hang' => now(),
                'tong_tien' => $totalAmount,
                'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
                'dia_chi_giao_hang' => $shippingAddress,
                'ma_tai_khoan' => $accountId,
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
                $transactionCode = 'CASH-' . $order->ma_don_hang;
            }

            Payment::create([
                'so_tien' => $totalAmount,
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
     * Get paginated customer order history with status filter (ORD-04).
     *
     * @param int $accountId
     * @param array<string, mixed> $params
     * @return LengthAwarePaginator
     */
    public function getCustomerOrders(int $accountId, array $params = []): LengthAwarePaginator
    {
        $query = Order::with(['dishes', 'payment'])
            ->where('ma_tai_khoan', $accountId);

        // Filter by status if provided (support English enum key or Vietnamese value)
        if (!empty($params['status'])) {
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

        $perPage = (int) ($params['perPage'] ?? 10);
        return $query->paginate($perPage);
    }

    /**
     * Get detailed order by ID belonging to customer (ORD-05).
     *
     * @param int $accountId
     * @param int $orderId
     * @return Order
     * @throws HttpException
     */
    public function getCustomerOrderDetail(int $accountId, int $orderId): Order
    {
        $order = Order::with(['dishes', 'payment'])
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        return $order;
    }

    /**
     * Customer cancels order (ORD-06). Only allowed when status is PENDING_CONFIRMATION.
     *
     * @param int $accountId
     * @param int $orderId
     * @param string|null $cancelReason
     * @return Order
     * @throws HttpException
     */
    public function cancelCustomerOrder(int $accountId, int $orderId, ?string $cancelReason = null): Order
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        // Quy tắc: Khách hàng chỉ có thể hủy đơn khi đơn hàng đang ở trạng thái PENDING_CONFIRMATION
        if ($order->trang_thai !== OrderStatus::PENDING_CONFIRMATION) {
            throw new HttpException(409, 'Order cannot be cancelled because it is already being prepared by the kitchen');
        }

        $order->trang_thai = OrderStatus::CANCELLED;
        $order->save();

        if ($order->payment && $cancelReason && empty($order->payment->ly_do_hoan_tien)) {
            $order->payment->ly_do_hoan_tien = $cancelReason;
            $order->payment->save();
        }

        return $order;
    }
}

