<?php

namespace App\Services\Dining;

use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketSource;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\DiningTable;
use App\Models\Invoice;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\ServiceRequest;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Payments\VietQrService;
use Illuminate\Support\Collection;

/**
 * Builds the minimal live snapshot a guest device may see for its table.
 *
 * Only data of the visit's own session is included; no internal table/session ids,
 * no staff identity and no other guest's details. A `version` fingerprint lets the
 * client poll cheaply: when it matches, only `{changed: false}` is returned.
 */
class LiveStateService
{
    public function __construct(
        private readonly GuestAccessService $guestAccess,
        private readonly VietQrService $vietQrService,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(DiningTable $table, ?TableVisit $visit, ?string $knownVersion = null): array
    {
        $activeSession = $this->guestAccess->activeSessionFor($table);
        $session = $this->visibleSession($visit, $activeSession);
        $pendingCheckIn = $this->guestAccess->pendingCheckInFor($table);

        $state = [
            'table' => [
                'tableName' => $table->ten_ban,
                'location' => $table->vi_tri,
                'capacity' => (int) $table->suc_chua,
                'status' => $table->trang_thai->name,
                'inService' => $table->trang_thai !== TableStatus::MAINTENANCE,
            ],
            'entry' => [
                'hasActiveSession' => (bool) $activeSession,
                'requiresJoinCode' => $activeSession && ! ($visit && $this->guestAccess->isBoundToSession($visit, $activeSession)),
                'joinCodeLocked' => $activeSession && ! ($visit && $this->guestAccess->isBoundToSession($visit, $activeSession)) && $activeSession->isJoinCodeLocked(),
                'hasPendingCheckIn' => (bool) $pendingCheckIn && (! $visit || (int) $pendingCheckIn->ma_luot !== (int) $visit->ma_luot),
            ],
            'visit' => $visit ? $this->visitData($visit, $activeSession) : null,
            'session' => null,
            'rounds' => [],
            'serviceRequests' => [],
            'invoice' => null,
            'summary' => null,
        ];

        if ($session) {
            $state = array_merge($state, $this->sessionData($session, $visit, $activeSession));
        }

        $version = substr(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 0, 20);

        $meta = [
            'version' => $version,
            'serverTime' => now()->toIso8601String(),
            'sla' => [
                'warningMinutes' => (int) config('dining.kitchen.warning_minutes'),
                'lateMinutes' => (int) config('dining.kitchen.late_minutes'),
            ],
        ];

        if ($knownVersion !== null && hash_equals($version, $knownVersion)) {
            return ['changed' => false, ...$meta];
        }

        return ['changed' => true, ...$meta, ...$state];
    }

    private function visibleSession(?TableVisit $visit, ?ServiceSession $activeSession): ?ServiceSession
    {
        if (! $visit || $visit->trang_thai !== TableVisitStatus::ACTIVE || ! $visit->ma_phien) {
            return null;
        }

        if ($activeSession && (int) $activeSession->ma_phien === (int) $visit->ma_phien) {
            return $activeSession;
        }

        $session = ServiceSession::query()->find($visit->ma_phien);
        $readableUntil = $session?->dong_luc?->copy()->addHours((int) config('dining.visit_readable_hours', 6));

        return $session && $readableUntil && $readableUntil->isFuture() ? $session : null;
    }

    /** @return array<string, mixed> */
    private function visitData(TableVisit $visit, ?ServiceSession $activeSession): array
    {
        $isCurrent = $this->guestAccess->isBoundToSession($visit, $activeSession);

        return [
            'status' => $visit->trang_thai->name,
            'guestName' => $visit->ten_khach,
            'guestCount' => $visit->so_khach,
            'rejectReason' => $visit->trang_thai === TableVisitStatus::REJECTED ? $visit->ly_do_tu_choi : null,
            'requestedAt' => $visit->tao_luc?->toIso8601String(),
            'expiresAt' => $visit->trang_thai === TableVisitStatus::PENDING
                ? $visit->tao_luc->copy()->addMinutes((int) config('dining.check_in_expires_minutes', 15))->toIso8601String()
                : null,
            'isCurrentSession' => $isCurrent,
        ];
    }

    /** @return array<string, mixed> */
    private function sessionData(ServiceSession $session, ?TableVisit $visit, ?ServiceSession $activeSession): array
    {
        $session->loadMissing(['orders.items.dish', 'invoice.payment']);
        $isCurrent = $visit && $this->guestAccess->isBoundToSession($visit, $activeSession);
        $items = $session->orders
            ->reject(fn ($order) => $order->trang_thai === OrderStatus::CANCELLED)
            ->flatMap(fn ($order) => $order->items)
            ->reject(fn (OrderItem $item) => $item->trang_thai_bep === KitchenItemStatus::DRAFT)
            ->values();

        $tickets = KitchenTicket::query()
            ->with('items:ma_chi_tiet')
            ->whereIn('ma_don_hang', $session->orders->pluck('ma_don_hang'))
            ->orderBy('gui_luc')
            ->orderBy('ma_phieu_bep')
            ->get();

        $requests = ServiceRequest::query()
            ->where('ma_phien', $session->ma_phien)
            ->orderByDesc('ma_yeu_cau')
            ->limit(20)
            ->get();

        $billable = $items->reject(fn (OrderItem $item) => in_array($item->trang_thai_bep, [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true));

        return [
            'session' => [
                'status' => $session->trang_thai->name,
                'openedAt' => $session->mo_luc?->toIso8601String(),
                'closedAt' => $session->dong_luc?->toIso8601String(),
                'guestCount' => (int) $session->so_luong_khach,
                'isCurrent' => $isCurrent,
                'canOrder' => $isCurrent && $session->trang_thai === PosSessionStatus::OPEN,
                'canRequestService' => $isCurrent,
                // Only devices already in this session may see and share the table code.
                'joinCode' => $isCurrent ? $session->ma_tham_gia : null,
            ],
            'rounds' => $this->rounds($tickets, $items, $visit),
            'serviceRequests' => $requests->map(fn (ServiceRequest $request) => [
                'requestId' => (int) $request->ma_yeu_cau,
                'type' => $request->loai->name,
                'label' => $request->loai->label(),
                'note' => $request->ghi_chu,
                'status' => $request->trang_thai->name,
                'isMine' => $visit && (int) $request->ma_luot === (int) $visit->ma_luot,
                'canCancel' => $isCurrent && $visit && (int) $request->ma_luot === (int) $visit->ma_luot
                    && $request->trang_thai->value === 'NEW',
                'createdAt' => $request->tao_luc?->toIso8601String(),
                'acknowledgedAt' => $request->nhan_luc?->toIso8601String(),
                'completedAt' => $request->hoan_thanh_luc?->toIso8601String(),
            ])->values()->all(),
            'invoice' => $this->invoiceData($session->invoice, $billable),
            'summary' => [
                'itemCount' => (int) $billable->sum('so_luong'),
                'inKitchenCount' => (int) $billable->filter(fn (OrderItem $item) => in_array($item->trang_thai_bep, [KitchenItemStatus::SENT, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING], true))->sum('so_luong'),
                'readyCount' => (int) $billable->filter(fn (OrderItem $item) => $item->trang_thai_bep === KitchenItemStatus::READY)->sum('so_luong'),
                'servedCount' => (int) $billable->filter(fn (OrderItem $item) => $item->trang_thai_bep === KitchenItemStatus::SERVED)->sum('so_luong'),
                'subTotal' => round((float) $billable->sum(fn (OrderItem $item) => (float) $item->don_gia * (int) $item->so_luong), 2),
            ],
        ];
    }

    /**
     * Group sent items by ordering round. Items without a ticket (legacy data) are
     * shown as a single staff round.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, KitchenTicket>  $tickets
     * @param  Collection<int, OrderItem>  $items
     * @return list<array<string, mixed>>
     */
    private function rounds($tickets, Collection $items, ?TableVisit $visit): array
    {
        $itemsById = $items->keyBy('ma_chi_tiet');
        $claimed = [];
        $rounds = [];

        foreach ($tickets as $ticket) {
            $roundItems = $ticket->items
                ->map(fn ($item) => $itemsById->get($item->ma_chi_tiet))
                ->filter()
                ->values();
            if ($roundItems->isEmpty()) {
                continue;
            }
            array_push($claimed, ...$roundItems->pluck('ma_chi_tiet')->all());
            $rounds[] = $this->roundData(
                count($rounds) + 1,
                $ticket->nguon ?? KitchenTicketSource::POS,
                $ticket->gui_luc?->toIso8601String(),
                $visit && (int) $ticket->ma_luot === (int) $visit->ma_luot,
                $roundItems
            );
        }

        $unticketed = $items->reject(fn (OrderItem $item) => in_array($item->ma_chi_tiet, $claimed, true))->values();
        if ($unticketed->isNotEmpty()) {
            array_unshift($rounds, $this->roundData(0, KitchenTicketSource::POS, null, false, $unticketed));
            foreach ($rounds as $index => $round) {
                $rounds[$index]['roundNumber'] = $index + 1;
            }
        }

        return $rounds;
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<string, mixed>
     */
    private function roundData(int $number, KitchenTicketSource $source, ?string $sentAt, bool $isMine, Collection $items): array
    {
        return [
            'roundNumber' => $number,
            'source' => $source->name,
            'isMine' => $isMine,
            'sentAt' => $sentAt,
            'items' => $items->map(fn (OrderItem $item) => [
                'lineId' => (int) $item->ma_chi_tiet,
                'dishName' => $item->ten_mon_snapshot,
                'imageUrl' => $item->dish?->hinh_anh,
                'quantity' => (int) $item->so_luong,
                'unitPrice' => (float) $item->don_gia,
                'note' => $item->ghi_chu,
                'status' => $item->trang_thai_bep->name,
                'statusChangedAt' => $item->bep_cap_nhat_luc?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $billable
     * @return array<string, mixed>|null
     */
    private function invoiceData(?Invoice $invoice, Collection $billable): ?array
    {
        if (! $invoice || $invoice->trang_thai === InvoiceStatus::VOID) {
            return null;
        }

        $lines = $billable
            ->groupBy(fn (OrderItem $item) => $item->ten_mon_snapshot.'|'.$item->don_gia)
            ->map(fn (Collection $group) => [
                'dishName' => $group->first()->ten_mon_snapshot,
                'quantity' => (int) $group->sum('so_luong'),
                'unitPrice' => (float) $group->first()->don_gia,
                'subTotal' => round((float) $group->sum(fn (OrderItem $item) => (float) $item->don_gia * (int) $item->so_luong), 2),
            ])
            ->values()
            ->all();

        $payment = $invoice->payment;

        return [
            'invoiceNumber' => 'HD'.str_pad((string) $invoice->ma_hoa_don, 5, '0', STR_PAD_LEFT),
            'status' => $invoice->trang_thai->name,
            'lines' => $lines,
            'subTotal' => (float) $invoice->tam_tinh,
            'discountTotal' => (float) $invoice->giam_gia,
            'totalAmount' => (float) $invoice->tong_thanh_toan,
            'createdAt' => $invoice->tao_luc?->toIso8601String(),
            'paidAt' => $invoice->thanh_toan_luc?->toIso8601String(),
            'payment' => $payment && $invoice->trang_thai === InvoiceStatus::PAID ? [
                'method' => $payment->phuong_thuc->name,
                'status' => $payment->trang_thai->name,
                'amount' => (float) $payment->so_tien,
            ] : null,
            'qr' => $invoice->trang_thai === InvoiceStatus::OPEN
                ? collect($this->vietQrService->build(
                    (float) $invoice->tong_thanh_toan,
                    $this->vietQrService->invoiceTransferContent((int) $invoice->ma_hoa_don)
                ))->only(['qrImageUrl', 'transferContent', 'accountName', 'accountNo'])->all()
                : null,
        ];
    }
}
