<?php

namespace App\Services\Staff;

use App\Enums\FulfillmentType;
use App\Enums\ItemVoidReason;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketSource;
use App\Enums\OrderLineType;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Models\Account;
use App\Models\Dish;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Điều chỉnh dòng món sau khi gửi bếp (ADR-024).
 *
 * - Hủy SENT/QUEUED (chưa chế biến): STAFF, lý do bắt buộc, không tính tiền.
 * - Hủy PREPARING/READY/SERVED: chỉ ADMIN (STAFF gửi yêu cầu duyệt). Lỗi nhà hàng → không
 *   thu; khách đổi ý → vẫn thu trừ khi ADMIN miễn tiền.
 * - Bếp từ chối (hết nguyên liệu, làm sai, hỏng): không thu, có thể tạm ngừng bán món.
 * - Làm lại do lỗi nhà hàng: dòng mới giá 0 liên kết dòng gốc, không thu hai lần.
 * - Đổi món: hủy dòng cũ theo quy tắc trên + dòng nháp mới liên kết, giữ snapshot cũ.
 * - Tách dòng: chia số lượng, bảo toàn tổng số lượng và số tiền.
 *
 * Tiền của dòng luôn là don_gia × so_luong_tinh_tien; mọi thay đổi tính lại tổng đơn và phát
 * hành lại hóa đơn đang mở (phiên bản mới). Hóa đơn đã trả thì phần chênh thành "cần hoàn".
 */
class ItemCorrectionService
{
    private const COOKING_OR_DONE = [KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED];

    private const NOT_STARTED = [KitchenItemStatus::SENT, KitchenItemStatus::QUEUED];

    public function __construct(
        private readonly PosOrderService $posOrderService,
        private readonly BillingService $billingService,
        private readonly KitchenService $kitchenService,
        private readonly PaymentLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function requiresApproval(OrderItem $item): bool
    {
        return in_array($item->trang_thai_bep, self::COOKING_OR_DONE, true);
    }

    /**
     * @param  array{reason: string, note: string, quantity?: ?int, waive?: bool}  $data
     */
    public function void(OrderItem $item, Account $actor, array $data): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $data): OrderItem {
            [$order, $locked] = $this->lock($item);
            $this->assertCorrectable($order, $locked);

            if ($locked->trang_thai_bep === KitchenItemStatus::DRAFT) {
                throw new HttpException(409, 'Remove a draft item instead of cancelling it.');
            }

            $reason = ItemVoidReason::from($data['reason']);
            $cooked = $this->requiresApproval($locked);
            if ($cooked && ! $actor->isAdmin()) {
                throw new HttpException(403, 'Cancelling an item the kitchen has started requires administrator approval.');
            }
            $waive = (bool) ($data['waive'] ?? false);
            if ($waive && ! $actor->isAdmin()) {
                throw new HttpException(403, 'Only an administrator can waive the charge for an item.');
            }

            $target = $this->splitOff($locked, (int) ($data['quantity'] ?? $locked->so_luong), $actor);
            $before = $this->snapshot($target);

            // Chưa chế biến hoặc lỗi phía nhà hàng: không thu. Khách đổi ý sau khi bếp nấu: vẫn thu, trừ khi ADMIN miễn.
            $chargedQuantity = (! $cooked || $reason->isRestaurantFault() || $waive) ? 0 : (int) $target->so_luong;

            $target->update([
                'trang_thai_bep_truoc' => $target->trang_thai_bep->value,
                'trang_thai_bep' => KitchenItemStatus::CANCELLED,
                'so_luong_tinh_tien' => $chargedQuantity,
                'loai_ly_do_huy' => $reason,
                'ly_do_huy' => $data['note'],
                'mien_tien' => $waive,
                'ma_nguoi_mien_tien' => $waive ? $actor->ma_tai_khoan : null,
                'ma_nguoi_huy' => $actor->ma_tai_khoan,
                'huy_luc' => now(),
                'bep_cap_nhat_luc' => now(),
            ]);

            $this->afterChange($order, $actor, 'Hủy món: '.$target->ten_mon_snapshot);
            $this->audit->record($actor, $cooked ? 'ITEM_VOIDED_AFTER_COOKING' : 'ITEM_VOIDED', 'ORDER_ITEM', (int) $target->ma_chi_tiet, $data['note'], $before, $this->snapshot($target->fresh()));

            return $target->fresh(['dish', 'tickets']);
        });
    }

    /** ADMIN miễn tiền một dòng (món tặng, bồi hoàn) mà không đổi trạng thái bếp. */
    public function waive(OrderItem $item, Account $admin, string $note): OrderItem
    {
        if (! $admin->isAdmin()) {
            throw new HttpException(403, 'Only an administrator can waive the charge for an item.');
        }

        return DB::transaction(function () use ($item, $admin, $note): OrderItem {
            [$order, $locked] = $this->lock($item);
            $this->assertCorrectable($order, $locked, allowCancelled: true);
            if ((int) $locked->so_luong_tinh_tien === 0) {
                throw new HttpException(409, 'This item is already free of charge.');
            }

            $before = $this->snapshot($locked);
            $locked->update(['so_luong_tinh_tien' => 0, 'mien_tien' => true, 'ma_nguoi_mien_tien' => $admin->ma_tai_khoan, 'ly_do_huy' => $locked->ly_do_huy ?? $note]);
            $this->afterChange($order, $admin, 'Miễn tiền món: '.$locked->ten_mon_snapshot);
            $this->audit->record($admin, 'ITEM_CHARGE_WAIVED', 'ORDER_ITEM', (int) $locked->ma_chi_tiet, $note, $before, $this->snapshot($locked->fresh()));

            return $locked->fresh(['dish', 'tickets']);
        });
    }

    /**
     * Bếp từ chối món (hết nguyên liệu, làm sai, hỏng). Không thu tiền; có thể tạm ngừng bán.
     *
     * @param  array{reason: string, note: string, markSoldOut?: bool}  $data
     */
    public function reject(OrderItem $item, Account $actor, array $data): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $data): OrderItem {
            [$order, $locked] = $this->lock($item);
            $this->assertCorrectable($order, $locked);

            if (! in_array($locked->trang_thai_bep, [KitchenItemStatus::SENT, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING], true)) {
                throw new HttpException(409, 'Only items waiting for or being prepared can be rejected by the kitchen.');
            }

            $reason = ItemVoidReason::from($data['reason']);
            if (! $reason->isRestaurantFault() || $reason === ItemVoidReason::NOT_SERVED) {
                throw new HttpException(422, 'Kitchen rejection must give a kitchen reason (out of stock, mistake, damaged).');
            }

            $before = $this->snapshot($locked);
            $locked->update([
                'trang_thai_bep_truoc' => $locked->trang_thai_bep->value,
                'trang_thai_bep' => KitchenItemStatus::REJECTED,
                'so_luong_tinh_tien' => 0,
                'loai_ly_do_huy' => $reason,
                'ly_do_huy' => $data['note'],
                'ma_nguoi_huy' => $actor->ma_tai_khoan,
                'huy_luc' => now(),
                'bep_cap_nhat_luc' => now(),
            ]);

            if (! empty($data['markSoldOut'])) {
                $dish = Dish::query()->lockForUpdate()->find($locked->ma_mon_an);
                if ($dish && $dish->con_ban) {
                    $dish->update(['con_ban' => false]);
                    $this->audit->record($actor, 'DISH_MARKED_SOLD_OUT', 'DISH', (int) $dish->ma_mon_an, $data['note']);
                }
            }

            $this->afterChange($order, $actor, 'Bếp từ chối món: '.$locked->ten_mon_snapshot);
            $this->audit->record($actor, 'ITEM_REJECTED_BY_KITCHEN', 'ORDER_ITEM', (int) $locked->ma_chi_tiet, $data['note'], $before, $this->snapshot($locked->fresh()));

            return $locked->fresh(['dish', 'tickets']);
        });
    }

    /**
     * Làm lại món do lỗi nhà hàng: dòng mới REMAKE giá 0 gửi thẳng xuống bếp.
     *
     * @param  array{reason: string, note: string}  $data
     */
    public function remake(OrderItem $item, Account $actor, array $data): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $data): OrderItem {
            [$order, $locked] = $this->lock($item);
            $this->assertCorrectable($order, $locked, allowCancelled: true);

            $reason = ItemVoidReason::from($data['reason']);
            if (! $reason->isRestaurantFault()) {
                throw new HttpException(422, 'A free remake is only for a restaurant mistake. Add a new item for a customer request.');
            }
            if (! in_array($locked->trang_thai_bep, [KitchenItemStatus::READY, KitchenItemStatus::SERVED, KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true)) {
                throw new HttpException(409, 'Only a finished, served or withdrawn item can be remade.');
            }

            $remake = OrderItem::create([
                'ma_don_hang' => $order->ma_don_hang,
                'ma_mon_an' => $locked->ma_mon_an,
                'ten_mon_snapshot' => $locked->ten_mon_snapshot,
                'so_luong' => $locked->so_luong,
                'so_luong_tinh_tien' => 0,
                'don_gia' => $locked->don_gia,
                'ghi_chu' => trim('Làm lại: '.$data['note']),
                'trang_thai_bep' => KitchenItemStatus::SENT,
                'loai_dong' => OrderLineType::REMAKE,
                'ma_chi_tiet_goc' => $locked->ma_chi_tiet,
                'loai_ly_do_huy' => $reason,
                'client_request_id' => 'remake-'.$locked->ma_chi_tiet.'-'.now()->format('YmdHisv'),
                'bep_cap_nhat_luc' => now(),
            ]);
            $ticket = KitchenTicket::create([
                'ma_don_hang' => $order->ma_don_hang,
                'ma_nhan_vien' => $actor->ma_tai_khoan,
                'trang_thai' => 'OPEN',
                'gui_luc' => now(),
                'nguon' => KitchenTicketSource::POS,
            ]);
            $ticket->items()->attach([$remake->ma_chi_tiet]);

            $order->update(['trang_thai' => OrderStatus::PREPARING]);
            $this->kitchenService->synchronizeOrderStatus($order->fresh());
            $this->audit->record($actor, 'ITEM_REMADE', 'ORDER_ITEM', (int) $locked->ma_chi_tiet, $data['note'], null, ['remakeItemId' => (int) $remake->ma_chi_tiet, 'reason' => $reason->name]);

            return $remake->fresh(['dish', 'tickets']);
        });
    }

    /**
     * Đổi món tại bàn: hủy dòng cũ (theo quy tắc hủy) và thêm dòng nháp mới liên kết.
     *
     * @param  array{dishId: int, quantity: int, reason: string, note: string, waive?: bool}  $data
     */
    public function exchange(OrderItem $item, Account $actor, array $data): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $data): OrderItem {
            $order = Order::query()->with('serviceSession')->findOrFail($item->ma_don_hang);
            if ($order->loai_phuc_vu !== FulfillmentType::DINE_IN) {
                throw new HttpException(409, 'Exchanging dishes is only available for dine-in orders.');
            }

            $voided = $this->void($item, $actor, ['reason' => $data['reason'], 'note' => $data['note'], 'waive' => $data['waive'] ?? false]);
            $replacement = $this->posOrderService->addItem($order->fresh(), [
                'dishId' => $data['dishId'],
                'quantity' => $data['quantity'],
                'note' => 'Đổi từ '.$voided->ten_mon_snapshot,
                'clientRequestId' => 'exchange-'.$voided->ma_chi_tiet,
            ]);
            $replacement->update(['loai_dong' => OrderLineType::EXCHANGE, 'ma_chi_tiet_goc' => $voided->ma_chi_tiet]);
            $this->audit->record($actor, 'ITEM_EXCHANGED', 'ORDER_ITEM', (int) $voided->ma_chi_tiet, $data['note'], null, ['replacementItemId' => (int) $replacement->ma_chi_tiet]);

            return $replacement->fresh(['dish', 'tickets']);
        });
    }

    /** Tách một dòng thành hai (ví dụ phục vụ trước một phần). Tổng số lượng và tiền không đổi. */
    public function split(OrderItem $item, Account $actor, int $quantity): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $quantity): OrderItem {
            [$order, $locked] = $this->lock($item);
            $this->assertCorrectable($order, $locked);
            if ($locked->trang_thai_bep === KitchenItemStatus::DRAFT) {
                throw new HttpException(409, 'Edit the quantity of a draft item instead of splitting it.');
            }

            $part = $this->splitOff($locked, $quantity, $actor, mustSplit: true);
            $this->audit->record($actor, 'ITEM_SPLIT', 'ORDER_ITEM', (int) $locked->ma_chi_tiet, null, null, ['newItemId' => (int) $part->ma_chi_tiet, 'quantity' => $quantity]);

            return $part->fresh(['dish', 'tickets']);
        });
    }

    /**
     * Tách $quantity phần khỏi dòng thành dòng mới (cùng trạng thái, cùng phiếu bếp). Nếu lấy
     * toàn bộ thì trả lại chính dòng đó.
     */
    private function splitOff(OrderItem $item, int $quantity, Account $actor, bool $mustSplit = false): OrderItem
    {
        $total = (int) $item->so_luong;
        if ($quantity < 1 || $quantity > $total) {
            throw new HttpException(422, "Quantity must be between 1 and {$total}.");
        }
        if ($quantity === $total) {
            if ($mustSplit) {
                throw new HttpException(422, 'Choose fewer portions than the whole line to split it.');
            }

            return $item;
        }

        $billed = (int) ($item->so_luong_tinh_tien ?? $total);
        $partBilled = min($billed, $quantity);
        $item->update(['so_luong' => $total - $quantity, 'so_luong_tinh_tien' => $billed - $partBilled]);

        $part = OrderItem::create([
            'ma_don_hang' => $item->ma_don_hang,
            'ma_mon_an' => $item->ma_mon_an,
            'ten_mon_snapshot' => $item->ten_mon_snapshot,
            'so_luong' => $quantity,
            'so_luong_tinh_tien' => $partBilled,
            'don_gia' => $item->don_gia,
            'ghi_chu' => $item->ghi_chu,
            'trang_thai_bep' => $item->trang_thai_bep,
            'loai_dong' => $item->loai_dong ?? OrderLineType::NORMAL,
            'ma_chi_tiet_goc' => $item->ma_chi_tiet,
            'client_request_id' => 'split-'.$item->ma_chi_tiet.'-'.now()->format('YmdHisv').'-'.$quantity,
            'bep_cap_nhat_luc' => $item->bep_cap_nhat_luc,
            'ma_nguoi_cap_nhat_bep' => $actor->ma_tai_khoan,
        ]);
        foreach ($item->tickets()->pluck('PHIEU_BEP.ma_phieu_bep') as $ticketId) {
            KitchenTicket::query()->find($ticketId)?->items()->attach([$part->ma_chi_tiet]);
        }

        return $part;
    }

    private function afterChange(Order $order, Account $actor, string $reason): void
    {
        $this->posOrderService->recalculate($order->fresh());
        $this->kitchenService->synchronizeOrderStatus($order->fresh());
        $this->billingService->reissueOpenInvoice($order->fresh(), $actor, $reason);
        $this->ledger->syncOrderPayment($order->fresh());
    }

    /** @return array{0: Order, 1: OrderItem} */
    private function lock(OrderItem $item): array
    {
        $order = Order::query()->with('serviceSession')->lockForUpdate()->findOrFail($item->ma_don_hang);
        $locked = OrderItem::query()->lockForUpdate()->findOrFail($item->ma_chi_tiet);

        return [$order, $locked];
    }

    private function assertCorrectable(Order $order, OrderItem $item, bool $allowCancelled = false): void
    {
        if ($order->trang_thai === OrderStatus::CANCELLED || $order->trang_thai === OrderStatus::COMPLETED) {
            throw new HttpException(409, 'This order is already closed.');
        }
        if ($order->serviceSession && ! in_array($order->serviceSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
            throw new HttpException(409, 'This service session has already ended.');
        }
        if (! $allowCancelled && in_array($item->trang_thai_bep, [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true)) {
            throw new HttpException(409, 'This item has already been withdrawn.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(OrderItem $item): array
    {
        return [
            'status' => $item->trang_thai_bep?->name,
            'quantity' => (int) $item->so_luong,
            'billedQuantity' => (int) ($item->so_luong_tinh_tien ?? $item->so_luong),
            'unitPrice' => (float) $item->don_gia,
            'dishName' => $item->ten_mon_snapshot,
            'reason' => $item->loai_ly_do_huy?->name,
            'waived' => (bool) $item->mien_tien,
        ];
    }
}
