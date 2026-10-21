<?php

namespace App\Services\Dining;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketSource;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Staff\PosOrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sends a guest's QR ordering round straight to the kitchen.
 *
 * A round is appended to the session's single POS order (the same order staff use at
 * the POS) and recorded as a kitchen ticket with source TABLE_QR, so KDS, billing and
 * reporting need no second order pipeline. Prices always come from MON_AN.
 */
class TableOrderService
{
    public function __construct(
        private readonly GuestAccessService $guestAccess,
        private readonly PosOrderService $posOrderService,
    ) {}

    /**
     * @param  list<array{dishId: int|string, quantity: int|string, note?: ?string}>  $lines
     * @return array{ticket: KitchenTicket, created: bool}
     */
    public function submit(DiningTable $table, TableVisit $visit, array $lines, string $idempotencyKey): array
    {
        $normalized = collect($lines)->map(fn (array $line) => [
            'dishId' => (int) $line['dishId'],
            'quantity' => (int) $line['quantity'],
            'note' => $this->cleanNote($line['note'] ?? null),
        ])->values()->all();

        $requestHash = hash('sha256', json_encode([
            'visitId' => (int) $visit->ma_luot,
            'lines' => $normalized,
        ], JSON_THROW_ON_ERROR));

        $replay = $this->findReplay($visit, $idempotencyKey, $requestHash);
        if ($replay) {
            return ['ticket' => $replay, 'created' => false];
        }

        try {
            return DB::transaction(fn (): array => $this->placeRound($table, $visit, $normalized, $idempotencyKey, $requestHash));
        } catch (UniqueConstraintViolationException $exception) {
            $replay = $this->findReplay($visit, $idempotencyKey, $requestHash);
            if ($replay) {
                return ['ticket' => $replay, 'created' => false];
            }

            throw $exception;
        }
    }

    /**
     * @param  list<array{dishId: int, quantity: int, note: ?string}>  $lines
     * @return array{ticket: KitchenTicket, created: bool}
     */
    private function placeRound(DiningTable $table, TableVisit $visit, array $lines, string $idempotencyKey, string $requestHash): array
    {
        $activeSession = $this->guestAccess->activeSessionFor($table);
        $session = $activeSession
            ? ServiceSession::query()->lockForUpdate()->find($activeSession->ma_phien)
            : null;
        $lockedVisit = TableVisit::query()->lockForUpdate()->findOrFail($visit->ma_luot);

        if (! $session || ! $this->guestAccess->isBoundToSession($lockedVisit, $session)) {
            throw new HttpException(409, 'This table session has ended. Scan the table QR code again to start a new one.');
        }

        if ($session->trang_thai === PosSessionStatus::AWAITING_PAYMENT) {
            throw new HttpException(409, 'Your bill is being prepared. Ask a member of staff if you would like to order more.');
        }

        if ($session->trang_thai !== PosSessionStatus::OPEN) {
            throw new HttpException(409, 'Service session is not open for ordering.');
        }

        $order = $this->sessionOrder($session);

        $dishes = Dish::query()
            ->whereIn('ma_mon_an', array_unique(array_column($lines, 'dishId')))
            ->lockForUpdate()
            ->get()
            ->keyBy('ma_mon_an');

        foreach ($lines as $line) {
            $dish = $dishes->get($line['dishId']);
            if (! $dish || $dish->isDeleted || ! $dish->isVisible) {
                throw new HttpException(404, 'Dish not found');
            }
            if (! $dish->isAvailable) {
                throw new HttpException(409, "Dish '{$dish->ten_mon_an}' has just sold out");
            }
        }

        $ticket = KitchenTicket::create([
            'ma_don_hang' => $order->ma_don_hang,
            'ma_nhan_vien' => $session->ma_nhan_vien,
            'trang_thai' => 'OPEN',
            'gui_luc' => now(),
            'nguon' => KitchenTicketSource::TABLE_QR,
            'ma_luot' => $lockedVisit->ma_luot,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
        ]);

        $itemIds = [];
        foreach ($lines as $index => $line) {
            $dish = $dishes->get($line['dishId']);
            $itemIds[] = OrderItem::create([
                'ma_don_hang' => $order->ma_don_hang,
                'ma_mon_an' => $dish->ma_mon_an,
                'ten_mon_snapshot' => $dish->ten_mon_an,
                'so_luong' => $line['quantity'],
                'don_gia' => $dish->gia_tien,
                'ghi_chu' => $line['note'],
                'trang_thai_bep' => KitchenItemStatus::SENT,
                'client_request_id' => "{$idempotencyKey}:{$index}",
                'bep_cap_nhat_luc' => now(),
            ])->ma_chi_tiet;
        }

        $ticket->items()->attach($itemIds);
        $order->update(['trang_thai' => OrderStatus::PREPARING]);
        $this->posOrderService->recalculate($order);

        return ['ticket' => $ticket->load('items'), 'created' => true];
    }

    /**
     * The session's single dine-in order, created on the first round if staff have not
     * started one at the POS yet.
     */
    private function sessionOrder(ServiceSession $session): Order
    {
        $order = Order::query()
            ->where('ma_phien', $session->ma_phien)
            ->where('trang_thai', '!=', OrderStatus::CANCELLED->value)
            ->latest('ma_don_hang')
            ->lockForUpdate()
            ->first();

        if (! $order) {
            return Order::create([
                'ngay_dat_hang' => now(),
                'tong_tien' => 0,
                'trang_thai' => OrderStatus::DRAFT,
                'dia_chi_giao_hang' => null,
                'ma_tai_khoan' => $session->ma_nhan_vien,
                'ma_phien' => $session->ma_phien,
                'kenh' => OrderChannel::POS,
                'loai_phuc_vu' => FulfillmentType::DINE_IN,
                'trang_thai_giao_nhan' => FulfillmentStatus::SERVING,
                'phien_ban' => 1,
            ]);
        }

        if (! in_array($order->trang_thai, [OrderStatus::DRAFT, OrderStatus::PREPARING, OrderStatus::READY], true)) {
            throw new HttpException(409, 'This order no longer accepts new items.');
        }

        return $order;
    }

    private function findReplay(TableVisit $visit, string $idempotencyKey, string $requestHash): ?KitchenTicket
    {
        $existing = KitchenTicket::query()->with('items')->where('idempotency_key', $idempotencyKey)->first();

        if (! $existing) {
            return null;
        }

        if ((int) $existing->ma_luot !== (int) $visit->ma_luot || ! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw new HttpException(409, 'Idempotency key was already used with a different request.');
        }

        return $existing;
    }

    private function cleanNote(mixed $note): ?string
    {
        if (! is_string($note)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($note)) ?? '');

        return $clean !== '' ? $clean : null;
    }
}
