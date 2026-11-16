<?php

namespace App\Modules\Order\Services\Admin;

use App\Modules\Core\Enums\OrderStatus;
use App\Modules\Core\Enums\PaymentMethod;
use App\Modules\Core\Enums\PaymentStatus;
use App\Modules\Order\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderService
{
    /**
     * Get paginated orders list with search and status filters (ORD-07).
     *
     * @param array<string, mixed> $params
     * @return LengthAwarePaginator
     */
    public function getOrders(array $params = []): LengthAwarePaginator
    {
        $query = Order::with(['dishes', 'payment', 'account.userProfile']);

        // Filter by status if provided (support English enum key or Vietnamese value)
        if (!empty($params['status'])) {
            $statusInput = trim((string) $params['status']);
            $matchedStatus = OrderStatus::fromName($statusInput);
            if ($matchedStatus) {
                $query->where('trang_thai', $matchedStatus->value);
            }
        }

        // Search by orderId, recipient address, customer username, or customer full name/phone
        if (!empty($params['search'])) {
            $search = trim((string) $params['search']);
            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->orWhere('ma_don_hang', (int) $search);
                }
                $q->orWhere('dia_chi_giao_hang', 'LIKE', "%{$search}%")
                    ->orWhereHas('account', function ($sub) use ($search) {
                        $sub->where('ten_dang_nhap', 'LIKE', "%{$search}%")
                            ->orWhereHas('userProfile', function ($pSub) use ($search) {
                                $pSub->where('ho_ten', 'LIKE', "%{$search}%")
                                    ->orWhere('so_dien_thoai', 'LIKE', "%{$search}%");
                            });
                    });
            });
        }

        $query->orderByDesc('ma_don_hang');

        $perPage = (int) ($params['perPage'] ?? 10);
        return $query->paginate($perPage);
    }

    /**
     * Get order details by ID for Admin.
     *
     * @param int $orderId
     * @return Order
     * @throws HttpException
     */
    public function getOrderDetail(int $orderId): Order
    {
        $order = Order::with(['dishes', 'payment', 'account.userProfile'])
            ->where('ma_don_hang', $orderId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        return $order;
    }

    /**
     * Update order status sequentially according to State Machine (ORD-08 -> ORD-11).
     *
     * @param int $orderId
     * @param string $targetStatusInput
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function updateStatus(int $orderId, string $targetStatusInput): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        $targetStatus = OrderStatus::fromName($targetStatusInput);
        if (!$targetStatus) {
            throw new HttpException(400, "Invalid target status: {$targetStatusInput}");
        }

        $currentStatus = $order->trang_thai instanceof OrderStatus
            ? $order->trang_thai
            : OrderStatus::fromName((string) $order->trang_thai);

        if (!$currentStatus) {
            throw new HttpException(400, 'Current order status is invalid');
        }

        // Validate state machine transition
        if (!$currentStatus->canTransitionTo($targetStatus)) {
            throw new HttpException(400, "Invalid status transition from {$currentStatus->name} to {$targetStatus->name}");
        }

        // ORD-11: Payment synchronization when completing order
        if ($targetStatus === OrderStatus::COMPLETED) {
            $payment = $order->payment;
            if ($payment) {
                $pMethod = $payment->phuong_thuc instanceof PaymentMethod
                    ? $payment->phuong_thuc
                    : PaymentMethod::tryFrom($payment->phuong_thuc);

                $pStatus = $payment->trang_thai instanceof PaymentStatus
                    ? $payment->trang_thai
                    : PaymentStatus::tryFrom($payment->trang_thai);

                if ($pMethod === PaymentMethod::CASH) {
                    $payment->trang_thai = PaymentStatus::PAID;
                    $payment->save();
                } elseif ($pMethod === PaymentMethod::BANK_TRANSFER_QR && $pStatus === PaymentStatus::UNPAID) {
                    throw new HttpException(400, 'Đơn hàng chuyển khoản QR chưa được xác nhận thanh toán thành công');
                }
            }
        }

        $previousStatusName = $currentStatus->name;
        $order->trang_thai = $targetStatus;
        $order->save();

        return [
            'orderId' => (int) $order->orderId,
            'previousStatus' => $previousStatusName,
            'currentStatus' => $targetStatus->name,
        ];
    }

    /**
     * Admin cancels order with optional reason (ORD-12).
     *
     * @param int $orderId
     * @param string|null $cancelReason
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function cancelOrder(int $orderId, ?string $cancelReason = null): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        $currentStatus = $order->trang_thai instanceof OrderStatus
            ? $order->trang_thai
            : OrderStatus::fromName((string) $order->trang_thai);

        if (!$currentStatus || !$currentStatus->canTransitionTo(OrderStatus::CANCELLED)) {
            $prevName = $currentStatus?->name ?? 'UNKNOWN';
            throw new HttpException(400, "Invalid status transition from {$prevName} to CANCELLED");
        }

        $order->trang_thai = OrderStatus::CANCELLED;
        $order->save();

        if ($order->payment && $cancelReason) {
            $order->payment->ly_do_hoan_tien = $cancelReason;
            $order->payment->save();
        }

        return [
            'orderId' => (int) $order->orderId,
            'status' => 'CANCELLED',
        ];
    }

    /**
     * Staff/Admin confirms payment received for order (ORD-13).
     *
     * @param int $orderId
     * @param string|null $note
     * @param int|null $staffId
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function confirmPayment(int $orderId, ?string $note = null, ?int $staffId = null): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        $payment = $order->payment;
        if (!$payment) {
            $payment = \App\Modules\Payment\Models\Payment::create([
                'so_tien' => $order->tong_tien,
                'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
                'trang_thai' => PaymentStatus::PAID,
                'ma_don_hang' => $order->ma_don_hang,
                'ma_giao_dich' => 'MANUAL-' . strtoupper(uniqid()),
            ]);
        } else {
            $payment->trang_thai = PaymentStatus::PAID;
            if (empty($payment->ma_giao_dich)) {
                $payment->ma_giao_dich = 'MANUAL-' . strtoupper(uniqid());
            }
        }

        $payment->log_callback = json_encode([
            'type' => 'manual_confirmation',
            'confirmed_by' => $staffId ? "staff_{$staffId}" : 'staff_or_admin',
            'note' => $note ?? 'Confirmed manually by restaurant staff',
            'time' => now()->toIso8601String(),
        ]);
        $payment->save();

        if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION) {
            $order->trang_thai = OrderStatus::CONFIRMED;
            $order->save();
        }

        return [
            'orderId' => (int) $order->orderId,
            'paymentId' => (int) $payment->paymentId,
            'paymentStatus' => 'PAID',
            'orderStatus' => $order->trang_thai instanceof OrderStatus ? $order->trang_thai->name : (string) $order->trang_thai,
            'amount' => (float) $payment->so_tien,
        ];
    }
}

