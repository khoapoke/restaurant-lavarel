<?php

namespace App\Services\Staff;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Models\Account;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Shared\AuditLogger;
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

    private const WORKING_STATUSES = ['SENT', 'QUEUED', 'PREPARING', 'READY'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Items the kitchen should see now: dine-in tickets from POS plus online orders the
     * restaurant has confirmed. Scheduled online orders appear only from
     * scheduledAt − lead time (ADR-025); before that they are listed by upcoming().
     */
    public function activeItems(): Collection
    {
        $releaseBefore = now()->addMinutes((int) config('restaurant.fulfillment.scheduled_prep_lead_minutes', 30));

        return OrderItem::with(['dish', 'order.serviceSession.tables', 'tickets'])
            ->where(function (Builder $query) use ($releaseBefore): void {
                $query->where(function (Builder $working) use ($releaseBefore): void {
                    $working->whereIn('trang_thai_bep', self::WORKING_STATUSES)
                        ->whereHas('order', function (Builder $order) use ($releaseBefore): void {
                            $order->where(function (Builder $visible): void {
                                $visible->where('kenh', OrderChannel::POS->value)
                                    ->where('trang_thai', '!=', OrderStatus::CANCELLED->value);
                            })->orWhere(function (Builder $online) use ($releaseBefore): void {
                                $online->where('kenh', '!=', OrderChannel::POS->value)
                                    ->whereIn('trang_thai', [
                                        OrderStatus::CONFIRMED->value,
                                        OrderStatus::PREPARING->value,
                                        OrderStatus::READY->value,
                                    ])
                                    // Đơn hẹn chỉ hiện khi tới giờ xuống bếp, hoặc đã được quản lý cho bắt đầu sớm (PREPARING/READY).
                                    ->where(fn (Builder $timing) => $timing->whereNull('thoi_gian_nhan_du_kien')
                                        ->orWhere('thoi_gian_nhan_du_kien', '<=', $releaseBefore)
                                        ->orWhereIn('trang_thai', [OrderStatus::PREPARING->value, OrderStatus::READY->value]));
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

    /** Confirmed online orders scheduled for later, so the kitchen can plan ahead. */
    public function upcoming(): Collection
    {
        $releaseBefore = now()->addMinutes((int) config('restaurant.fulfillment.scheduled_prep_lead_minutes', 30));

        return Order::query()
            ->with(['items' => fn ($query) => $query->whereNotIn('trang_thai_bep', [KitchenItemStatus::CANCELLED->value, KitchenItemStatus::REJECTED->value])])
            ->where('kenh', '!=', OrderChannel::POS->value)
            ->where('trang_thai', OrderStatus::CONFIRMED->value)
            ->where('thoi_gian_nhan_du_kien', '>', $releaseBefore)
            ->orderBy('thoi_gian_nhan_du_kien')
            ->limit(30)
            ->get();
    }

    public function updateStatus(OrderItem $item, KitchenItemStatus $target, ?Account $actor = null): OrderItem
    {
        return DB::transaction(function () use ($item, $target, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->ma_don_hang);
            $lockedItem = OrderItem::query()->lockForUpdate()->findOrFail($item->ma_chi_tiet);

            $this->assertOrderAcceptsKitchenUpdates($order, $target);

            $expected = self::NEXT_STATUS[$lockedItem->trang_thai_bep->value] ?? null;
            if ($expected !== $target) {
                throw new HttpException(409, "Invalid kitchen transition from {$lockedItem->trang_thai_bep->name} to {$target->name}.");
            }

            $lockedItem->update([
                'trang_thai_bep_truoc' => $lockedItem->trang_thai_bep->value,
                'trang_thai_bep' => $target,
                'bep_cap_nhat_luc' => now(),
                'ma_nguoi_cap_nhat_bep' => $actor?->ma_tai_khoan,
            ]);
            $this->synchronizeOrderStatus($order);

            return $lockedItem->fresh(['dish', 'order.serviceSession.tables', 'tickets']);
        });
    }

    /**
     * Hoàn tác bước bếp vừa bấm nhầm (trong cửa sổ cấu hình, mặc định 2 phút) khi chưa phát
     * sinh bước phụ thuộc: phiên chưa đóng, đơn online chưa bàn giao, món chưa bị hủy.
     */
    public function undo(OrderItem $item, Account $actor): OrderItem
    {
        return DB::transaction(function () use ($item, $actor) {
            $order = Order::query()->with('serviceSession')->lockForUpdate()->findOrFail($item->ma_don_hang);
            $lockedItem = OrderItem::query()->lockForUpdate()->findOrFail($item->ma_chi_tiet);

            $previous = $lockedItem->trang_thai_bep_truoc ? KitchenItemStatus::tryFrom($lockedItem->trang_thai_bep_truoc) : null;
            $windowSeconds = (int) config('restaurant.kitchen.undo_window_seconds', 120);
            if (! $previous || ! $this->canUndo($lockedItem, $order, $windowSeconds)) {
                throw new HttpException(409, 'This kitchen step can no longer be undone.');
            }

            $lockedItem->update([
                'trang_thai_bep' => $previous,
                'trang_thai_bep_truoc' => null,
                'bep_cap_nhat_luc' => now(),
                'ma_nguoi_cap_nhat_bep' => $actor->ma_tai_khoan,
            ]);

            if ($order->loai_phuc_vu !== FulfillmentType::DINE_IN && $order->trang_thai === OrderStatus::READY && $previous !== KitchenItemStatus::READY) {
                $order->update(['trang_thai' => OrderStatus::PREPARING, 'trang_thai_giao_nhan' => FulfillmentStatus::PENDING, 'san_sang_luc' => null]);
            }
            $this->synchronizeOrderStatus($order->fresh());
            $this->audit->record($actor, 'KITCHEN_STEP_UNDONE', 'ORDER_ITEM', (int) $lockedItem->ma_chi_tiet, null, ['status' => $item->trang_thai_bep?->name], ['status' => $previous->name]);

            return $lockedItem->fresh(['dish', 'order.serviceSession.tables', 'tickets']);
        });
    }

    public function canUndo(OrderItem $item, ?Order $order = null, ?int $windowSeconds = null): bool
    {
        $windowSeconds ??= (int) config('restaurant.kitchen.undo_window_seconds', 120);
        $order ??= $item->order;
        $previous = $item->trang_thai_bep_truoc ? KitchenItemStatus::tryFrom($item->trang_thai_bep_truoc) : null;

        if (! $previous || ! $item->bep_cap_nhat_luc || $item->bep_cap_nhat_luc->lt(now()->subSeconds($windowSeconds))) {
            return false;
        }
        if (! in_array($item->trang_thai_bep, [KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED], true)) {
            return false;
        }
        if (! $order || in_array($order->trang_thai, [OrderStatus::CANCELLED, OrderStatus::COMPLETED], true)) {
            return false;
        }
        if ($order->serviceSession && ! in_array($order->serviceSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
            return false;
        }

        // Đơn online đã bàn giao (đang giao/đã lấy) không được lùi trạng thái bếp.
        return ! in_array($order->trang_thai_giao_nhan, [FulfillmentStatus::OUT_FOR_DELIVERY, FulfillmentStatus::DELIVERED, FulfillmentStatus::COLLECTED, FulfillmentStatus::DELIVERY_FAILED], true);
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

        // Màn bếp ẩn đơn hẹn trước giờ chuẩn bị; API cũng chặn để không vượt quy tắc bằng cách gọi thẳng.
        if ($order->awaitingKitchenRelease()) {
            throw new HttpException(409, sprintf(
                'This order is scheduled for %s; the kitchen starts it at %s. A manager can start it earlier from the order screen with a reason.',
                $order->thoi_gian_nhan_du_kien->format('H:i'),
                $order->kitchenReleaseAt()->format('H:i')
            ));
        }
    }

    /**
     * Đồng bộ trạng thái đơn theo các món còn hiệu lực (bỏ qua món đã hủy/từ chối).
     */
    public function synchronizeOrderStatus(Order $order): void
    {
        $statuses = OrderItem::where('ma_don_hang', $order->ma_don_hang)
            ->whereNotIn('trang_thai_bep', [KitchenItemStatus::CANCELLED->value, KitchenItemStatus::REJECTED->value, KitchenItemStatus::DRAFT->value])
            ->pluck('trang_thai_bep');

        if (in_array($order->trang_thai, [OrderStatus::CANCELLED, OrderStatus::COMPLETED, OrderStatus::DRAFT, OrderStatus::PENDING_CONFIRMATION], true)) {
            return;
        }

        $allReady = $statuses->isNotEmpty() && $statuses->every(
            fn (KitchenItemStatus $status) => in_array($status, [KitchenItemStatus::READY, KitchenItemStatus::SERVED], true)
        );

        if ($order->loai_phuc_vu === FulfillmentType::DINE_IN) {
            if ($statuses->isNotEmpty()) {
                $order->update(['trang_thai' => $allReady ? OrderStatus::READY : OrderStatus::PREPARING]);
            }

            return;
        }

        if ($allReady && $order->trang_thai !== OrderStatus::READY) {
            $order->update([
                'trang_thai' => OrderStatus::READY,
                'trang_thai_giao_nhan' => $order->loai_phuc_vu->readyStatus(),
                'san_sang_luc' => now(),
            ]);

            return;
        }

        if (! $allReady && $order->trang_thai === OrderStatus::CONFIRMED && $statuses->contains(fn (KitchenItemStatus $status) => $status !== KitchenItemStatus::SENT)) {
            $order->update(['trang_thai' => OrderStatus::PREPARING]);
        }
    }
}
