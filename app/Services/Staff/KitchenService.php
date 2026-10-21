<?php

namespace App\Services\Staff;

use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenService
{
    private const NEXT_STATUS = [
        'SENT' => KitchenItemStatus::QUEUED,
        'QUEUED' => KitchenItemStatus::PREPARING,
        'PREPARING' => KitchenItemStatus::READY,
        'READY' => KitchenItemStatus::SERVED,
    ];

    /**
     * Items the kitchen should see: dine-in tickets from POS plus online orders the
     * restaurant has already confirmed. Served dine-in items stay visible while the
     * table session is still active so the floor team can see what has gone out.
     */
    public function activeItems(): Collection
    {
        $workingStatuses = [
            KitchenItemStatus::SENT->value,
            KitchenItemStatus::QUEUED->value,
            KitchenItemStatus::PREPARING->value,
            KitchenItemStatus::READY->value,
        ];

        return OrderItem::with(['dish', 'order.serviceSession.tables', 'tickets'])
            ->where(function (Builder $query) use ($workingStatuses): void {
                $query->where(function (Builder $working) use ($workingStatuses): void {
                    $working->whereIn('trang_thai_bep', $workingStatuses)
                        ->whereHas('order', function (Builder $order): void {
                            $order->where(function (Builder $visible): void {
                                $visible->where('kenh', OrderChannel::POS->value)
                                    ->where('trang_thai', '!=', OrderStatus::CANCELLED->value);
                            })->orWhere(function (Builder $online): void {
                                $online->where('kenh', '!=', OrderChannel::POS->value)
                                    ->whereIn('trang_thai', [
                                        OrderStatus::CONFIRMED->value,
                                        OrderStatus::PREPARING->value,
                                        OrderStatus::READY->value,
                                    ]);
                            });
                        });
                })->orWhere(function (Builder $served): void {
                    $served->where('trang_thai_bep', KitchenItemStatus::SERVED->value)
                        ->whereHas('order.serviceSession', fn (Builder $session) => $session->whereIn('trang_thai', [
                            PosSessionStatus::OPEN->value,
                            PosSessionStatus::AWAITING_PAYMENT->value,
                        ]));
                });
            })
            ->orderBy('ma_chi_tiet')
            ->get();
    }

    public function updateStatus(OrderItem $item, KitchenItemStatus $target): OrderItem
    {
        return DB::transaction(function () use ($item, $target) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->ma_don_hang);
            $lockedItem = OrderItem::query()->lockForUpdate()->findOrFail($item->ma_chi_tiet);

            $this->assertOrderAcceptsKitchenUpdates($order, $target);

            $expected = self::NEXT_STATUS[$lockedItem->trang_thai_bep->value] ?? null;
            if ($expected !== $target) {
                throw new HttpException(409, "Invalid kitchen transition from {$lockedItem->trang_thai_bep->name} to {$target->name}.");
            }

            $lockedItem->update(['trang_thai_bep' => $target, 'bep_cap_nhat_luc' => now()]);
            $this->synchronizeOrder($order);

            return $lockedItem->fresh(['dish', 'order.serviceSession.tables', 'tickets']);
        });
    }

    private function assertOrderAcceptsKitchenUpdates(Order $order, KitchenItemStatus $target): void
    {
        if ($order->trang_thai === OrderStatus::CANCELLED) {
            throw new HttpException(409, 'This order has been cancelled.');
        }

        if ($order->loai_phuc_vu === FulfillmentType::DINE_IN) {
            return;
        }

        if (! in_array($order->trang_thai, [OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY], true)) {
            throw new HttpException(409, 'Online orders must be confirmed before the kitchen can start.');
        }

        if ($target === KitchenItemStatus::SERVED) {
            throw new HttpException(409, 'Online orders are handed off from the order fulfillment screen, not the kitchen board.');
        }
    }

    private function synchronizeOrder(Order $order): void
    {
        $statuses = OrderItem::where('ma_don_hang', $order->ma_don_hang)
            ->whereNotIn('trang_thai_bep', [KitchenItemStatus::CANCELLED->value, KitchenItemStatus::REJECTED->value])
            ->pluck('trang_thai_bep');

        $allReady = $statuses->isNotEmpty() && $statuses->every(
            fn (KitchenItemStatus $status) => in_array($status, [KitchenItemStatus::READY, KitchenItemStatus::SERVED], true)
        );

        if ($order->loai_phuc_vu === FulfillmentType::DINE_IN) {
            $order->update(['trang_thai' => $allReady ? OrderStatus::READY : OrderStatus::PREPARING]);

            return;
        }

        if ($allReady) {
            $order->update([
                'trang_thai' => OrderStatus::READY,
                'trang_thai_giao_nhan' => $order->loai_phuc_vu->readyStatus(),
            ]);

            return;
        }

        if ($order->trang_thai === OrderStatus::CONFIRMED) {
            $order->update(['trang_thai' => OrderStatus::PREPARING]);
        }
    }
}
