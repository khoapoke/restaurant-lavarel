<?php

namespace App\Services\Staff;

use App\Enums\KitchenItemStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Models\Account;
use App\Models\Dish;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosOrderService
{
    public function createDraft(ServiceSession $session, Account $staff, array $data): Order
    {
        return DB::transaction(function () use ($session, $staff, $data) {
            $lockedSession = ServiceSession::lockForUpdate()->findOrFail($session->ma_phien);
            $this->assertSessionOpen($lockedSession);

            $existing = Order::where('ma_phien', $lockedSession->ma_phien)
                ->where('trang_thai', '!=', OrderStatus::CANCELLED->value)
                ->latest('ma_don_hang')
                ->first();

            if ($existing) {
                return $this->loadOrder($existing->ma_don_hang);
            }

            $order = Order::create([
                'ngay_dat_hang' => now(),
                'tong_tien' => 0,
                'trang_thai' => OrderStatus::DRAFT,
                'dia_chi_giao_hang' => '[DINE_IN] '.$lockedSession->tables()->pluck('ten_ban')->join(', '),
                'ghi_chu' => $data['note'] ?? null,
                'ma_tai_khoan' => $staff->ma_tai_khoan,
                'ma_phien' => $lockedSession->ma_phien,
                'kenh' => OrderChannel::POS,
                'phien_ban' => 1,
            ]);

            return $this->loadOrder($order->ma_don_hang);
        });
    }

    public function addItem(Order $order, array $data): OrderItem
    {
        $existingRequest = OrderItem::where('client_request_id', $data['clientRequestId'])->first();
        if ($existingRequest) {
            return $existingRequest->load('dish');
        }

        return DB::transaction(function () use ($order, $data) {
            $lockedOrder = Order::with('serviceSession')->lockForUpdate()->findOrFail($order->ma_don_hang);
            $this->assertOrderAcceptsItems($lockedOrder);
            $this->assertSessionOpen($lockedOrder->serviceSession);

            $dish = Dish::lockForUpdate()->findOrFail($data['dishId']);
            if (! $dish->isAvailable || ! $dish->isVisible || $dish->isDeleted) {
                throw new HttpException(409, 'Dish is currently unavailable.');
            }

            $note = isset($data['note']) ? trim((string) $data['note']) : null;
            $item = OrderItem::create([
                'ma_don_hang' => $lockedOrder->ma_don_hang,
                'ma_mon_an' => $dish->ma_mon_an,
                'ten_mon_snapshot' => $dish->ten_mon_an,
                'so_luong' => (int) $data['quantity'],
                'don_gia' => $dish->gia_tien,
                'ghi_chu' => $note ?: null,
                'trang_thai_bep' => KitchenItemStatus::DRAFT,
                'client_request_id' => $data['clientRequestId'],
            ]);

            $this->recalculate($lockedOrder);

            return $item->load('dish');
        });
    }

    public function updateItem(OrderItem $item, array $data): OrderItem
    {
        return DB::transaction(function () use ($item, $data) {
            $lockedItem = OrderItem::with('order.serviceSession')->lockForUpdate()->findOrFail($item->ma_chi_tiet);
            $this->assertDraftItem($lockedItem);
            $this->assertSessionOpen($lockedItem->order->serviceSession);

            $lockedItem->update([
                'so_luong' => $data['quantity'] ?? $lockedItem->so_luong,
                'ghi_chu' => array_key_exists('note', $data) ? ($data['note'] ?: null) : $lockedItem->ghi_chu,
            ]);
            $this->recalculate($lockedItem->order);

            return $lockedItem->fresh('dish');
        });
    }

    public function deleteItem(OrderItem $item): void
    {
        DB::transaction(function () use ($item) {
            $lockedItem = OrderItem::with('order.serviceSession')->lockForUpdate()->findOrFail($item->ma_chi_tiet);
            $this->assertDraftItem($lockedItem);
            $this->assertSessionOpen($lockedItem->order->serviceSession);
            $order = $lockedItem->order;
            $lockedItem->delete();
            $this->recalculate($order);
        });
    }

    /** @return array{order: Order, ticket: KitchenTicket} */
    public function sendKitchen(Order $order, Account $staff, array $itemIds): array
    {
        return DB::transaction(function () use ($order, $staff, $itemIds) {
            $lockedOrder = Order::with('serviceSession')->lockForUpdate()->findOrFail($order->ma_don_hang);
            $this->assertSessionOpen($lockedOrder->serviceSession);

            $items = OrderItem::where('ma_don_hang', $lockedOrder->ma_don_hang)
                ->whereIn('ma_chi_tiet', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count(array_unique($itemIds))) {
                throw new HttpException(404, 'One or more order items were not found.');
            }
            if ($items->contains(fn ($item) => $item->trang_thai_bep !== KitchenItemStatus::DRAFT)) {
                throw new HttpException(409, 'Only DRAFT items can be sent to the kitchen.');
            }

            $ticket = KitchenTicket::create([
                'ma_don_hang' => $lockedOrder->ma_don_hang,
                'ma_nhan_vien' => $staff->ma_tai_khoan,
                'trang_thai' => 'OPEN',
                'gui_luc' => now(),
            ]);
            $ticket->items()->attach($items->pluck('ma_chi_tiet')->all());
            OrderItem::whereIn('ma_chi_tiet', $items->pluck('ma_chi_tiet'))
                ->update(['trang_thai_bep' => KitchenItemStatus::SENT->value]);
            $lockedOrder->update([
                'trang_thai' => OrderStatus::PREPARING,
                'phien_ban' => $lockedOrder->phien_ban + 1,
            ]);

            return [
                'order' => $this->loadOrder($lockedOrder->ma_don_hang),
                'ticket' => $ticket->load('items'),
            ];
        });
    }

    private function recalculate(Order $order): void
    {
        $total = OrderItem::where('ma_don_hang', $order->ma_don_hang)
            ->whereNotIn('trang_thai_bep', [KitchenItemStatus::CANCELLED->value, KitchenItemStatus::REJECTED->value])
            ->get()
            ->sum(fn ($item) => (float) $item->don_gia * (int) $item->so_luong);

        $order->update(['tong_tien' => $total, 'phien_ban' => $order->phien_ban + 1]);
    }

    private function assertSessionOpen(?ServiceSession $session): void
    {
        if (! $session || $session->trang_thai !== PosSessionStatus::OPEN) {
            throw new HttpException(409, 'Service session is not open for ordering.');
        }
    }

    private function assertOrderAcceptsItems(Order $order): void
    {
        if (! in_array($order->trang_thai, [OrderStatus::DRAFT, OrderStatus::PREPARING], true)) {
            throw new HttpException(409, 'This order no longer accepts new items.');
        }
    }

    private function assertDraftItem(OrderItem $item): void
    {
        if ($item->trang_thai_bep !== KitchenItemStatus::DRAFT) {
            throw new HttpException(409, 'An item already sent to the kitchen cannot be edited directly.');
        }
    }

    private function loadOrder(int $orderId): Order
    {
        return Order::with('items.dish')->findOrFail($orderId);
    }
}
