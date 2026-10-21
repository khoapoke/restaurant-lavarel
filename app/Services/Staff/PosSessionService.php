<?php

namespace App\Services\Staff;

use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Dining\ServiceRequestService;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosSessionService
{
    private const ACTIVE_STATUSES = [
        PosSessionStatus::OPEN,
        PosSessionStatus::AWAITING_PAYMENT,
    ];

    public function __construct(
        private readonly TableStatusSynchronizer $tableStatusSynchronizer,
        private readonly ServiceRequestService $serviceRequests,
    ) {}

    public function getFloor(): Collection
    {
        return DiningTable::query()
            ->with([
                'serviceSessions' => function ($query): void {
                    $query->whereIn('PHIEN_PHUC_VU.trang_thai', $this->activeStatusValues())
                        ->with([
                            'staff.userProfile',
                            'reservation.account.userProfile',
                            'orders.items.tickets',
                            'serviceRequests' => fn ($requests) => $requests->whereIn('trang_thai', ServiceRequestStatus::openValues()),
                        ])
                        ->withCount(['visits as connected_guests_count' => fn ($visits) => $visits->where('trang_thai', TableVisitStatus::ACTIVE->value)]);
                },
                'visits' => fn ($query) => $query
                    ->where('trang_thai', TableVisitStatus::PENDING->value)
                    ->where('tao_luc', '>=', now()->subMinutes((int) config('dining.check_in_expires_minutes', 15)))
                    ->orderBy('tao_luc'),
                'reservations' => function ($query): void {
                    $query->where('trang_thai', ReservationStatus::CONFIRMED->value)
                        ->whereBetween('thoi_gian', [
                            now()->subMinutes(TableStatusSynchronizer::SLOT_MINUTES),
                            now()->endOfDay(),
                        ])
                        ->whereDoesntHave('serviceSessions', fn ($sessionQuery) => $sessionQuery->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
                        ->orderBy('thoi_gian')
                        ->with('account.userProfile');
                },
            ])
            ->orderBy('vi_tri')
            ->orderBy('ten_ban')
            ->get();
    }

    public function getSession(int $sessionId): ServiceSession
    {
        return ServiceSession::with([
            'staff.userProfile',
            'tables',
            'reservation.account.userProfile',
            'orders.items.dish',
            'orders.items.tickets',
            'invoice.payment',
            'serviceRequests' => fn ($query) => $query->orderByDesc('ma_yeu_cau')->limit(30),
            'visits' => fn ($query) => $query->where('trang_thai', TableVisitStatus::ACTIVE->value),
        ])->findOrFail($sessionId);
    }

    public function completeCleaning(DiningTable $table): DiningTable
    {
        return DB::transaction(function () use ($table) {
            $lockedTable = DiningTable::lockForUpdate()->findOrFail($table->ma_ban);

            if ($lockedTable->trang_thai !== TableStatus::CLEANING) {
                throw new HttpException(409, 'Only a table waiting for cleaning can be marked available.');
            }

            if ($this->tableHasActiveSession((int) $lockedTable->ma_ban)) {
                throw new HttpException(409, 'This table still belongs to an active service session.');
            }

            $lockedTable->update(['trang_thai' => TableStatus::AVAILABLE]);
            $this->tableStatusSynchronizer->refreshReservationHold($lockedTable);

            return $lockedTable->fresh()->load('serviceSessions');
        });
    }

    /**
     * Open a dine-in service session, optionally seating a confirmed reservation.
     *
     * @param  array{tableIds: list<int|string>, guestCount: int|string, idempotencyKey: string, reservationId?: int|string|null}  $data
     * @return array{session: ServiceSession, created: bool}
     */
    public function open(Account $staff, array $data): array
    {
        $tableIds = collect($data['tableIds'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $reservationId = ! empty($data['reservationId']) ? (int) $data['reservationId'] : null;
        $requestHash = hash('sha256', json_encode([
            'staffId' => (int) $staff->ma_tai_khoan,
            'tableIds' => $tableIds,
            'guestCount' => (int) $data['guestCount'],
            'reservationId' => $reservationId,
        ], JSON_THROW_ON_ERROR));

        $existing = ServiceSession::where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new HttpException(409, 'Idempotency key was already used with a different request.');
            }

            return ['session' => $this->getSession($existing->ma_phien), 'created' => false];
        }

        return DB::transaction(function () use ($staff, $data, $tableIds, $reservationId, $requestHash) {
            $tables = DiningTable::whereIn('ma_ban', $tableIds)
                ->orderBy('ma_ban')
                ->lockForUpdate()
                ->get();

            if ($tables->count() !== count($tableIds)) {
                throw new HttpException(404, 'One or more dining tables were not found.');
            }

            $reservation = $reservationId ? $this->lockSeatableReservation($reservationId, $tableIds) : null;

            $unavailable = $tables->first(fn (DiningTable $table) => ! in_array($table->trang_thai, [TableStatus::AVAILABLE, TableStatus::RESERVED], true));
            if ($unavailable) {
                throw new HttpException(409, "{$unavailable->ten_ban} is not available for a new service session.");
            }

            foreach ($tables as $table) {
                $conflict = $this->tableStatusSynchronizer->conflictingReservation((int) $table->ma_ban, $reservation?->ma_dat_ban);
                if ($conflict) {
                    throw new HttpException(409, "{$table->ten_ban} is held for reservation #{$conflict->ma_dat_ban} at {$conflict->thoi_gian->format('H:i')}. Seat that reservation or choose another table.");
                }
            }

            $hasOpenSession = ServiceSession::whereHas('tables', fn ($query) => $query->whereIn('BAN_AN.ma_ban', $tableIds))
                ->whereIn('trang_thai', $this->activeStatusValues())
                ->exists();

            if ($hasOpenSession) {
                throw new HttpException(409, 'One or more tables already belong to an open service session.');
            }

            $totalCapacity = $tables->sum('suc_chua');
            if ((int) $data['guestCount'] > $totalCapacity) {
                throw new HttpException(422, "Guest count exceeds the selected tables' capacity of {$totalCapacity}.");
            }

            $session = ServiceSession::create([
                'ma_nhan_vien' => $staff->ma_tai_khoan,
                'ma_dat_ban' => $reservation?->ma_dat_ban,
                'so_luong_khach' => $data['guestCount'],
                'trang_thai' => PosSessionStatus::OPEN,
                'mo_luc' => now(),
                'phien_ban' => 1,
                'idempotency_key' => $data['idempotencyKey'],
                'request_hash' => $requestHash,
            ]);

            $session->tables()->attach($tableIds);
            DiningTable::whereIn('ma_ban', $tableIds)->update(['trang_thai' => TableStatus::OCCUPIED->value]);

            return ['session' => $this->getSession($session->ma_phien), 'created' => true];
        });
    }

    /**
     * Cancel a session opened by mistake before anything reached the kitchen.
     */
    public function cancel(ServiceSession $session, string $reason): ServiceSession
    {
        return DB::transaction(function () use ($session, $reason): ServiceSession {
            $lockedSession = ServiceSession::with(['tables', 'invoice'])->lockForUpdate()->findOrFail($session->ma_phien);

            if ($lockedSession->trang_thai !== PosSessionStatus::OPEN) {
                throw new HttpException(409, 'Only an open session without an invoice can be cancelled.');
            }

            $orderIds = Order::where('ma_phien', $lockedSession->ma_phien)->pluck('ma_don_hang');
            $hasKitchenItems = OrderItem::whereIn('ma_don_hang', $orderIds)
                ->whereNotIn('trang_thai_bep', [KitchenItemStatus::DRAFT->value, KitchenItemStatus::CANCELLED->value])
                ->exists();

            if ($hasKitchenItems) {
                throw new HttpException(409, 'Items have already been sent to the kitchen. Settle the bill instead of cancelling.');
            }

            OrderItem::whereIn('ma_don_hang', $orderIds)->update(['trang_thai_bep' => KitchenItemStatus::CANCELLED->value]);
            Order::whereIn('ma_don_hang', $orderIds)->update([
                'trang_thai' => OrderStatus::CANCELLED->value,
                'tong_tien' => 0,
            ]);

            $lockedSession->update([
                'trang_thai' => PosSessionStatus::CANCELLED,
                'dong_luc' => now(),
                'ly_do_huy' => $reason,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);

            $this->serviceRequests->closeForSession((int) $lockedSession->ma_phien);

            foreach ($lockedSession->tables as $table) {
                $table->update(['trang_thai' => TableStatus::AVAILABLE]);
                $this->tableStatusSynchronizer->refreshReservationHold($table);
            }

            return $this->getSession($lockedSession->ma_phien);
        });
    }

    /** @param list<int> $tableIds */
    private function lockSeatableReservation(int $reservationId, array $tableIds): Reservation
    {
        $reservation = Reservation::query()->lockForUpdate()->find($reservationId);

        if (! $reservation || $reservation->trang_thai !== ReservationStatus::CONFIRMED) {
            throw new HttpException(409, 'Only a confirmed reservation can be seated.');
        }

        if (! in_array((int) $reservation->ma_ban, $tableIds, true)) {
            throw new HttpException(409, 'The reservation belongs to a different table.');
        }

        if (! $reservation->thoi_gian->isSameDay(now())) {
            throw new HttpException(409, 'Only reservations scheduled for today can be seated.');
        }

        if (ServiceSession::where('ma_dat_ban', $reservation->ma_dat_ban)->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value)->exists()) {
            throw new HttpException(409, 'This reservation has already been seated.');
        }

        return $reservation;
    }

    private function tableHasActiveSession(int $tableId): bool
    {
        return ServiceSession::whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $tableId))
            ->whereIn('trang_thai', $this->activeStatusValues())
            ->exists();
    }

    /** @return list<string> */
    private function activeStatusValues(): array
    {
        return array_map(fn (PosSessionStatus $status) => $status->value, self::ACTIVE_STATUSES);
    }
}
