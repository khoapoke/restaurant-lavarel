<?php

namespace App\Services\Admin;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderService
{
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

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('ma_don_hang', $keyword)
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
            ->with(['account.userProfile', 'dishes', 'payment'])
            ->findOrFail($orderId);
    }

    public function updateStatus(int $orderId, string $targetStatus): Order
    {
        return DB::transaction(function () use ($orderId, $targetStatus): Order {
            $order = Order::query()->with('payment')->lockForUpdate()->findOrFail($orderId);
            $target = $this->statusFromName($targetStatus);
            $current = $order->trang_thai;

            if (! $current->canTransitionTo($target)) {
                throw new HttpException(400, "Invalid status transition from {$current->name} to {$target->name}");
            }

            $updates = ['trang_thai' => $target];
            if ($target === OrderStatus::READY) {
                $updates['trang_thai_giao_nhan'] = $order->loai_phuc_vu->readyStatus();
            }

            $order->update($updates);

            return $this->getOrder($orderId);
        });
    }

    public function updateFulfillmentStatus(int $orderId, string $targetStatus): Order
    {
        return DB::transaction(function () use ($orderId, $targetStatus): Order {
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

            if ($target === FulfillmentStatus::OUT_FOR_DELIVERY) {
                $this->assertQrPaymentCompleted($order, 'handing the order to the driver');
            }

            $updates = ['trang_thai_giao_nhan' => $target];
            if ($target->completesOrder()) {
                $this->settlePaymentForCompletion($order);
                $updates['trang_thai'] = OrderStatus::COMPLETED;
            }

            $order->update($updates);

            return $this->getOrder($orderId);
        });
    }

    public function cancel(int $orderId, string $reason): Order
    {
        return DB::transaction(function () use ($orderId, $reason): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if (! $order->trang_thai->canBeCancelledByAdmin()) {
                throw new HttpException(409, 'This order can no longer be cancelled');
            }

            $existingNote = trim((string) $order->ghi_chu);
            $order->update([
                'trang_thai' => OrderStatus::CANCELLED,
                'ghi_chu' => trim($existingNote."\n[HỦY] ".$reason),
            ]);

            return $this->getOrder($orderId);
        });
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

    private function settlePaymentForCompletion(Order $order): void
    {
        if (! $order->payment) {
            throw new HttpException(409, 'Order has no payment record');
        }

        $this->assertQrPaymentCompleted($order, 'completing the order');

        if ($order->payment->phuong_thuc === PaymentMethod::CASH
            && $order->payment->trang_thai === PaymentStatus::UNPAID) {
            $order->payment->update([
                'trang_thai' => PaymentStatus::PAID,
                'ma_giao_dich' => $order->payment->ma_giao_dich ?: 'CASH-'.$order->ma_don_hang,
            ]);
        }
    }

    private function assertQrPaymentCompleted(Order $order, string $action): void
    {
        if (! $order->payment) {
            throw new HttpException(409, 'Order has no payment record');
        }

        if ($order->payment->phuong_thuc === PaymentMethod::BANK_TRANSFER_QR
            && $order->payment->trang_thai !== PaymentStatus::PAID) {
            throw new HttpException(400, "QR bank transfer must be paid before {$action}");
        }
    }
}
