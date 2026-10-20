<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
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
}
