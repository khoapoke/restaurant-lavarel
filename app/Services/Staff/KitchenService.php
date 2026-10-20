<?php

namespace App\Services\Staff;

use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenService
{
    public function activeItems(): Collection
    {
        return OrderItem::with(['dish', 'order.serviceSession.tables'])
            ->whereIn('trang_thai_bep', [
                KitchenItemStatus::SENT->value,
                KitchenItemStatus::QUEUED->value,
                KitchenItemStatus::PREPARING->value,
                KitchenItemStatus::READY->value,
            ])
            ->orderBy('ma_chi_tiet')
            ->get();
    }

    public function updateStatus(OrderItem $item, KitchenItemStatus $target): OrderItem
    {
        return DB::transaction(function () use ($item, $target) {
            $lockedItem = OrderItem::with('order')->lockForUpdate()->findOrFail($item->ma_chi_tiet);
            $allowed = [
                KitchenItemStatus::SENT->value => KitchenItemStatus::QUEUED,
                KitchenItemStatus::QUEUED->value => KitchenItemStatus::PREPARING,
                KitchenItemStatus::PREPARING->value => KitchenItemStatus::READY,
                KitchenItemStatus::READY->value => KitchenItemStatus::SERVED,
            ];

            $expected = $allowed[$lockedItem->trang_thai_bep->value] ?? null;
            if ($expected !== $target) {
                throw new HttpException(409, "Invalid kitchen transition from {$lockedItem->trang_thai_bep->name} to {$target->name}.");
            }

            $lockedItem->update(['trang_thai_bep' => $target]);
            $this->synchronizeOrder($lockedItem->order);

            return $lockedItem->fresh(['dish', 'order.serviceSession.tables']);
        });
    }

    private function synchronizeOrder($order): void
    {
        $statuses = OrderItem::where('ma_don_hang', $order->ma_don_hang)->pluck('trang_thai_bep');
        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => in_array($status, [KitchenItemStatus::READY->value, KitchenItemStatus::SERVED->value], true))) {
            $order->update(['trang_thai' => OrderStatus::READY]);

            return;
        }

        $order->update(['trang_thai' => OrderStatus::PREPARING]);
    }
}
