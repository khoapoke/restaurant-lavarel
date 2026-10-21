<?php

namespace App\Services\Dining;

use App\Enums\FulfillmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\DiningTable;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resets the single Live Dining demo table so a presenter can run the whole QR flow
 * again. Only the demo table and its own sessions are touched; it refuses to run in
 * production.
 */
class LiveDiningDemoService
{
    public const RESET_REASON = 'Làm mới bàn trình diễn Live Dining';

    public function __construct(
        private readonly ServiceRequestService $serviceRequests,
        private readonly TableStatusSynchronizer $tableStatusSynchronizer,
    ) {}

    public function isAllowed(): bool
    {
        return ! app()->isProduction() && (bool) config('dining.demo.enabled');
    }

    /**
     * @return array{table: DiningTable, url: string, closedSessions: int, cancelledSessions: int, expiredCheckIns: int}
     */
    public function prepare(bool $rotateToken = false): array
    {
        if (! $this->isAllowed()) {
            throw new RuntimeException('Live Dining demo mode is disabled in this environment.');
        }

        return DB::transaction(function () use ($rotateToken): array {
            $created = DiningTable::query()->firstOrCreate(
                ['ten_ban' => (string) config('dining.demo.table_name')],
                [
                    'suc_chua' => (int) config('dining.demo.table_capacity', 4),
                    'trang_thai' => TableStatus::AVAILABLE,
                    'vi_tri' => (string) config('dining.demo.table_location'),
                ]
            );
            $table = DiningTable::query()->lockForUpdate()->findOrFail($created->ma_ban);

            $sessions = ServiceSession::query()
                ->with('tables')
                ->whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))
                ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
                ->lockForUpdate()
                ->get();

            // Every check happens before the first write: a session shared with a regular
            // table belongs to real service, so the reset refuses instead of touching it.
            $shared = $sessions->first(fn (ServiceSession $session) => $session->tables->contains(fn (DiningTable $sessionTable) => (int) $sessionTable->ma_ban !== (int) $table->ma_ban));
            if ($shared) {
                $others = $shared->tables
                    ->reject(fn (DiningTable $sessionTable) => (int) $sessionTable->ma_ban === (int) $table->ma_ban)
                    ->pluck('ten_ban')
                    ->implode(', ');

                throw new RuntimeException("Session #{$shared->ma_phien} joins {$table->ten_ban} with {$others}. Close or split it at the POS before resetting the demo table.");
            }

            $closed = 0;
            $cancelled = 0;

            foreach ($sessions as $session) {
                $this->retireSession($session) ? $closed++ : $cancelled++;
            }

            $expired = TableVisit::query()
                ->where('ma_ban', $table->ma_ban)
                ->where('trang_thai', TableVisitStatus::PENDING->value)
                ->update(['trang_thai' => TableVisitStatus::EXPIRED->value, 'xu_ly_luc' => now()]);

            if ($rotateToken || ! $table->ma_qr) {
                $table->rotateQrToken();
            }

            $table->update(['trang_thai' => TableStatus::AVAILABLE]);
            $this->tableStatusSynchronizer->refreshReservationHold($table);
            $table->refresh();

            return [
                'table' => $table,
                'url' => (string) $table->qrUrl(),
                'closedSessions' => $closed,
                'cancelledSessions' => $cancelled,
                'expiredCheckIns' => $expired,
            ];
        });
    }

    /**
     * A paid session is closed normally (its revenue stays in reports); anything else
     * is cancelled and its unpaid invoice voided.
     */
    private function retireSession(ServiceSession $session): bool
    {
        $invoice = Invoice::query()->where('ma_phien', $session->ma_phien)->lockForUpdate()->first();
        $orderIds = Order::query()->where('ma_phien', $session->ma_phien)->pluck('ma_don_hang');
        $isPaid = $invoice && $invoice->trang_thai === InvoiceStatus::PAID;

        if ($isPaid) {
            OrderItem::query()->whereIn('ma_don_hang', $orderIds)
                ->whereNotIn('trang_thai_bep', [KitchenItemStatus::CANCELLED->value, KitchenItemStatus::REJECTED->value])
                ->update(['trang_thai_bep' => KitchenItemStatus::SERVED->value, 'bep_cap_nhat_luc' => now()]);
            Order::query()->whereIn('ma_don_hang', $orderIds)
                ->where('trang_thai', '!=', OrderStatus::CANCELLED->value)
                ->update([
                    'trang_thai' => OrderStatus::COMPLETED->value,
                    'trang_thai_giao_nhan' => FulfillmentStatus::SERVED->value,
                ]);
        } else {
            $invoice?->update(['trang_thai' => InvoiceStatus::VOID]);
            OrderItem::query()->whereIn('ma_don_hang', $orderIds)
                ->whereNotIn('trang_thai_bep', [KitchenItemStatus::SERVED->value, KitchenItemStatus::REJECTED->value])
                ->update(['trang_thai_bep' => KitchenItemStatus::CANCELLED->value, 'bep_cap_nhat_luc' => now()]);
            Order::query()->whereIn('ma_don_hang', $orderIds)->update(['trang_thai' => OrderStatus::CANCELLED->value]);
        }

        $session->update([
            'trang_thai' => $isPaid ? PosSessionStatus::CLOSED : PosSessionStatus::CANCELLED,
            'dong_luc' => now(),
            'ly_do_huy' => $isPaid ? null : self::RESET_REASON,
            'phien_ban' => $session->phien_ban + 1,
        ]);
        $this->serviceRequests->closeForSession((int) $session->ma_phien);

        return $isPaid;
    }
}
