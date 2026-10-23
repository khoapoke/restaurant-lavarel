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
use App\Services\Shared\AuditLogger;
use App\Services\Shared\OpeningHours;
use App\Services\Shared\ReservationSchedule;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phiên phục vụ tại bàn (ADR-025): nhận khách đặt bàn trong cửa sổ giờ, ngoài cửa sổ cần ADMIN;
 * khách vãng lai được báo giờ phải trả bàn trước lượt đặt kế tiếp; đổi số khách, chuyển/ghép bàn
 * giữ nguyên phiên, món, hóa đơn và lịch sử.
 */
class PosSessionService
{
    private const ACTIVE_STATUSES = [
        PosSessionStatus::OPEN,
        PosSessionStatus::AWAITING_PAYMENT,
    ];

    /** Cách trả bàn cũ khi chuyển bàn. */
    public const RELEASE_MODES = ['CLEANING', 'AVAILABLE', 'MAINTENANCE'];

    public function __construct(
        private readonly TableStatusSynchronizer $tableStatusSynchronizer,
        private readonly ServiceRequestService $serviceRequests,
        private readonly ReservationSchedule $schedule,
        private readonly OpeningHours $hours,
        private readonly ReservationDeskService $desk,
        private readonly AuditLogger $audit,
    ) {}

    public function getFloor(): Collection
    {
        $lookBack = (int) config('restaurant.reservations.grace_minutes', 20)
            + (int) config('restaurant.reservations.max_extension_minutes', 30)
            + (int) config('restaurant.reservations.auto_no_show_after_minutes', 60);

        return DiningTable::query()
            ->with([
                'serviceSessions' => function ($query): void {
                    $query->whereIn('PHIEN_PHUC_VU.trang_thai', $this->activeStatusValues())
                        ->with([
                            'staff.userProfile',
                            'reservation.account.userProfile',
                            'tables',
                            'orders.items.tickets',
                            'serviceRequests' => fn ($requests) => $requests->whereIn('trang_thai', ServiceRequestStatus::openValues()),
                        ])
                        ->withCount(['visits as connected_guests_count' => fn ($visits) => $visits->where('trang_thai', TableVisitStatus::ACTIVE->value)]);
                },
                'visits' => fn ($query) => $query
                    ->where('trang_thai', TableVisitStatus::PENDING->value)
                    ->where('tao_luc', '>=', now()->subMinutes((int) config('dining.check_in_expires_minutes', 15)))
                    ->orderBy('tao_luc'),
                // Lượt đặt hôm nay chưa nhận bàn, kể cả lượt đã quá hạn giữ chỗ (để cảnh báo và xử lý).
                'reservations' => function ($query) use ($lookBack): void {
                    $query->where('trang_thai', ReservationStatus::CONFIRMED->value)
                        ->whereBetween('thoi_gian', [now()->subMinutes($lookBack), now()->endOfDay()])
                        ->whereDoesntHave('serviceSessions', fn ($sessionQuery) => $sessionQuery->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
                        ->orderBy('thoi_gian')
                        ->with(['account.userProfile', 'extendedBy.userProfile']);
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
            'invoice.versions',
            'approvals.requester.userProfile',
            'approvals.approver.userProfile',
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
     * @param  array{tableIds: list<int|string>, guestCount: int|string, idempotencyKey: string, reservationId?: int|string|null, overrideReason?: ?string, relocationReason?: ?string, releaseReservationId?: int|string|null}  $data
     * @return array{session: ServiceSession, created: bool}
     */
    public function open(Account $staff, array $data): array
    {
        $tableIds = collect($data['tableIds'])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $reservationId = ! empty($data['reservationId']) ? (int) $data['reservationId'] : null;
        $releaseId = ! empty($data['releaseReservationId']) ? (int) $data['releaseReservationId'] : null;
        $guestCount = (int) $data['guestCount'];
        $requestHash = hash('sha256', json_encode([
            'staffId' => (int) $staff->ma_tai_khoan,
            'tableIds' => $tableIds,
            'guestCount' => $guestCount,
            'reservationId' => $reservationId,
            'releaseReservationId' => $releaseId,
        ], JSON_THROW_ON_ERROR));

        $existing = ServiceSession::where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new HttpException(409, 'Idempotency key was already used with a different request.');
            }

            return ['session' => $this->getSession($existing->ma_phien), 'created' => false];
        }

        $this->hours->assertAcceptsOrders();

        return DB::transaction(function () use ($staff, $data, $tableIds, $reservationId, $releaseId, $guestCount, $requestHash) {
            $tables = DiningTable::whereIn('ma_ban', $tableIds)
                ->orderBy('ma_ban')
                ->lockForUpdate()
                ->get();

            if ($tables->count() !== count($tableIds)) {
                throw new HttpException(404, 'One or more dining tables were not found.');
            }

            $overrideReason = trim((string) ($data['overrideReason'] ?? '')) ?: null;
            $relocationReason = trim((string) ($data['relocationReason'] ?? '')) ?: null;
            $reservation = $reservationId ? $this->lockSeatableReservation($reservationId, $tableIds, $staff, $overrideReason, $relocationReason) : null;

            // Nhường bàn cho khách mới sau khi hết hạn giữ chỗ: kết thúc lượt cũ trong cùng giao dịch.
            if ($releaseId) {
                $this->releaseExpiredHold($releaseId, $tableIds, $staff);
            }

            $unavailable = $tables->first(fn (DiningTable $table) => ! in_array($table->trang_thai, [TableStatus::AVAILABLE, TableStatus::RESERVED], true));
            if ($unavailable) {
                throw new HttpException(409, "{$unavailable->ten_ban} is not available for a new service session.");
            }

            $hasOpenSession = ServiceSession::whereHas('tables', fn ($query) => $query->whereIn('BAN_AN.ma_ban', $tableIds))
                ->whereIn('trang_thai', $this->activeStatusValues())
                ->exists();

            if ($hasOpenSession) {
                throw new HttpException(409, 'One or more tables already belong to an open service session.');
            }

            $totalCapacity = $tables->sum('suc_chua');
            if ($guestCount > $totalCapacity) {
                throw new HttpException(422, "Guest count exceeds the selected tables' capacity of {$totalCapacity}. Add another table.");
            }

            $expectedEnd = $this->leaveBy($tables, $guestCount, $reservation ? [(int) $reservation->ma_dat_ban] : []);

            $session = ServiceSession::create([
                'ma_nhan_vien' => $staff->ma_tai_khoan,
                'ma_dat_ban' => $reservation?->ma_dat_ban,
                'so_luong_khach' => $guestCount,
                'trang_thai' => PosSessionStatus::OPEN,
                'mo_luc' => now(),
                'du_kien_ket_thuc_luc' => $expectedEnd,
                'phien_ban' => 1,
                'idempotency_key' => $data['idempotencyKey'],
                'request_hash' => $requestHash,
            ]);

            $session->tables()->attach($tableIds);
            DiningTable::whereIn('ma_ban', $tableIds)->update(['trang_thai' => TableStatus::OCCUPIED->value]);

            $this->audit->record($staff, 'SESSION_OPENED', 'SESSION', (int) $session->ma_phien, $overrideReason ?? $relocationReason, null, [
                'tables' => $tables->pluck('ten_ban')->values()->all(),
                'guestCount' => $guestCount,
                'reservationId' => $reservation?->ma_dat_ban,
                'bookedGuestCount' => $reservation ? (int) $reservation->so_luong_khach : null,
                'expectedEndAt' => $expectedEnd->toIso8601String(),
                'outsideSeatingWindow' => $overrideReason !== null,
                'relocatedFromTableId' => $relocationReason ? (int) $reservation?->ma_ban : null,
            ]);
            if ($reservation) {
                $this->audit->record($staff, 'RESERVATION_SEATED', 'RESERVATION', (int) $reservation->ma_dat_ban, $overrideReason ?? $relocationReason, null, [
                    'sessionId' => (int) $session->ma_phien,
                    'actualGuestCount' => $guestCount,
                ]);
            }

            return ['session' => $this->getSession($session->ma_phien), 'created' => true];
        });
    }

    /** Khách đến thêm hoặc về bớt trong lúc ăn (trong sức chứa các bàn của phiên). */
    public function changeGuestCount(ServiceSession $session, Account $actor, int $guestCount, ?string $reason = null): ServiceSession
    {
        return DB::transaction(function () use ($session, $actor, $guestCount, $reason): ServiceSession {
            $locked = $this->lockActive($session);
            $capacity = (int) $locked->tables->sum('suc_chua');

            if ($guestCount < 1) {
                throw new HttpException(422, 'A session needs at least one guest.');
            }
            if ($guestCount > $capacity) {
                throw new HttpException(422, "The tables seat {$capacity}. Add another table first.");
            }

            $before = ['guestCount' => (int) $locked->so_luong_khach];
            $locked->update([
                'so_luong_khach' => $guestCount,
                'du_kien_ket_thuc_luc' => $this->leaveBy($locked->tables, $guestCount, [], $locked->mo_luc),
                'phien_ban' => $locked->phien_ban + 1,
            ]);
            $this->audit->record($actor, 'SESSION_GUEST_COUNT_CHANGED', 'SESSION', (int) $locked->ma_phien, $reason, $before, ['guestCount' => $guestCount]);

            return $this->getSession($locked->ma_phien);
        });
    }

    /**
     * Chuyển phiên sang bàn khác (đổi bàn, bàn gặp sự cố) hoặc ghép thêm bàn. Phiên, món,
     * hóa đơn và lịch sử giữ nguyên; bàn rời đi chuyển CLEANING (đã dùng), AVAILABLE (chưa dùng)
     * hoặc MAINTENANCE (sự cố).
     *
     * @param  list<int>  $targetTableIds  danh sách bàn mới của phiên
     */
    public function changeTables(ServiceSession $session, Account $actor, array $targetTableIds, string $reason, string $releaseAs = 'CLEANING'): ServiceSession
    {
        if (! in_array($releaseAs, self::RELEASE_MODES, true)) {
            throw new HttpException(422, 'Unknown release mode for the previous tables.');
        }

        return DB::transaction(function () use ($session, $actor, $targetTableIds, $reason, $releaseAs): ServiceSession {
            $locked = $this->lockActive($session);
            $targetIds = collect($targetTableIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $currentIds = $locked->tables->pluck('ma_ban')->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($targetIds === []) {
                throw new HttpException(422, 'Choose at least one table.');
            }
            if ($targetIds === $currentIds) {
                throw new HttpException(422, 'The session is already on these tables.');
            }

            $allIds = array_values(array_unique([...$targetIds, ...$currentIds]));
            $tables = DiningTable::query()->whereIn('ma_ban', $allIds)->orderBy('ma_ban')->lockForUpdate()->get()->keyBy('ma_ban');
            if (count(array_diff($targetIds, $tables->keys()->map(fn ($id) => (int) $id)->all())) > 0) {
                throw new HttpException(404, 'One or more dining tables were not found.');
            }

            $added = array_values(array_diff($targetIds, $currentIds));
            $released = array_values(array_diff($currentIds, $targetIds));
            $busyUntil = $this->schedule->sessionBusyUntil($locked);

            foreach ($added as $tableId) {
                $table = $tables[$tableId];
                if (! in_array($table->trang_thai, [TableStatus::AVAILABLE, TableStatus::RESERVED], true)) {
                    throw new HttpException(409, "{$table->ten_ban} is not available.");
                }
                if ($this->tableHasActiveSession($tableId)) {
                    throw new HttpException(409, "{$table->ten_ban} already belongs to another session.");
                }
                $conflict = $this->schedule->conflictingReservations($tableId, now(), $busyUntil)->first();
                if ($conflict) {
                    throw new HttpException(409, "{$table->ten_ban} is held for reservation #{$conflict->ma_dat_ban} at {$conflict->thoi_gian->format('H:i')}.");
                }
            }

            $capacity = (int) collect($targetIds)->sum(fn ($id) => (int) $tables[$id]->suc_chua);
            if ((int) $locked->so_luong_khach > $capacity) {
                throw new HttpException(422, "The new tables seat {$capacity} but the session has {$locked->so_luong_khach} guests.");
            }

            $before = ['tables' => collect($currentIds)->map(fn ($id) => $tables[$id]->ten_ban)->values()->all()];
            $locked->tables()->sync($targetIds);
            DiningTable::query()->whereIn('ma_ban', $added)->update(['trang_thai' => TableStatus::OCCUPIED->value]);

            foreach ($released as $tableId) {
                $table = $tables[$tableId];
                $table->update(['trang_thai' => TableStatus::from(match ($releaseAs) {
                    'AVAILABLE' => TableStatus::AVAILABLE->value,
                    'MAINTENANCE' => TableStatus::MAINTENANCE->value,
                    default => TableStatus::CLEANING->value,
                })]);
                $this->tableStatusSynchronizer->refreshReservationHold($table->fresh());
            }

            $locked->update([
                'du_kien_ket_thuc_luc' => $this->leaveBy(DiningTable::query()->whereIn('ma_ban', $targetIds)->get(), (int) $locked->so_luong_khach, [], $locked->mo_luc),
                'phien_ban' => $locked->phien_ban + 1,
            ]);
            $this->audit->record($actor, $released === [] ? 'SESSION_TABLES_MERGED' : 'SESSION_TABLES_MOVED', 'SESSION', (int) $locked->ma_phien, $reason, $before, [
                'tables' => collect($targetIds)->map(fn ($id) => $tables[$id]->ten_ban)->values()->all(),
                'releasedAs' => $released === [] ? null : $releaseAs,
            ]);

            return $this->getSession($locked->ma_phien);
        });
    }

    /**
     * Cancel a session opened by mistake before anything reached the kitchen.
     */
    public function cancel(ServiceSession $session, string $reason, ?Account $actor = null): ServiceSession
    {
        return DB::transaction(function () use ($session, $reason, $actor): ServiceSession {
            $lockedSession = ServiceSession::with(['tables', 'invoice'])->lockForUpdate()->findOrFail($session->ma_phien);

            if ($lockedSession->trang_thai !== PosSessionStatus::OPEN) {
                throw new HttpException(409, 'Only an open session without an invoice can be cancelled.');
            }

            $orderIds = Order::where('ma_phien', $lockedSession->ma_phien)->pluck('ma_don_hang');
            // Món đã hủy nhưng vẫn tính tiền (khách đổi ý sau khi nấu) cũng chặn hủy phiên: phải thanh toán.
            $hasKitchenItems = OrderItem::whereIn('ma_don_hang', $orderIds)
                ->where(fn ($query) => $query
                    ->whereNotIn('trang_thai_bep', [KitchenItemStatus::DRAFT->value, KitchenItemStatus::CANCELLED->value])
                    ->orWhere(fn ($billed) => $billed->where('trang_thai_bep', KitchenItemStatus::CANCELLED->value)->where('so_luong_tinh_tien', '>', 0)))
                ->exists();

            if ($hasKitchenItems) {
                throw new HttpException(409, 'Items have already been sent to the kitchen. Settle the bill instead of cancelling.');
            }

            OrderItem::whereIn('ma_don_hang', $orderIds)->update(['trang_thai_bep' => KitchenItemStatus::CANCELLED->value, 'so_luong_tinh_tien' => 0]);
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
            $this->audit->record($actor, 'SESSION_CANCELLED', 'SESSION', (int) $lockedSession->ma_phien, $reason);

            foreach ($lockedSession->tables as $table) {
                $table->update(['trang_thai' => TableStatus::AVAILABLE]);
                $this->tableStatusSynchronizer->refreshReservationHold($table);
            }

            return $this->getSession($lockedSession->ma_phien);
        });
    }

    /**
     * Giờ phải trả bàn: thời lượng dùng bàn theo số khách, nhưng không chạm lượt đặt kế tiếp
     * (trừ thời gian dọn). Khách vãng lai phải còn ít nhất min_walk_in_minutes.
     *
     * @param  iterable<DiningTable>  $tables
     * @param  list<int>  $ignoreReservationIds
     */
    private function leaveBy(iterable $tables, int $guestCount, array $ignoreReservationIds, ?Carbon $openedAt = null): Carbon
    {
        $start = $openedAt?->copy() ?? now();
        $leaveBy = $start->copy()->addMinutes($this->schedule->diningMinutes($guestCount));
        $minimum = (int) config('restaurant.reservations.min_walk_in_minutes', 30);

        foreach ($tables as $table) {
            $next = $this->schedule->nextReservation((int) $table->ma_ban, now(), $ignoreReservationIds);
            if (! $next) {
                continue;
            }

            $mustLeave = $next->thoi_gian->copy()->subMinutes($this->schedule->cleaningMinutes());
            if ($openedAt === null && $mustLeave->lessThan(now()->addMinutes($minimum))) {
                throw new HttpException(409, "{$table->ten_ban} is held for reservation #{$next->ma_dat_ban} at {$next->thoi_gian->format('H:i')}. Seat that reservation or choose another table.");
            }
            if ($mustLeave->lessThan($leaveBy)) {
                $leaveBy = $mustLeave;
            }
        }

        return $leaveBy;
    }

    /** @param list<int> $tableIds */
    private function lockSeatableReservation(int $reservationId, array $tableIds, Account $actor, ?string $overrideReason, ?string $relocationReason): Reservation
    {
        $reservation = Reservation::query()->lockForUpdate()->find($reservationId);

        if (! $reservation || $reservation->trang_thai !== ReservationStatus::CONFIRMED) {
            throw new HttpException(409, 'Only a confirmed reservation can be seated. Late guests whose booking was released are seated as walk-ins.');
        }

        if (ServiceSession::where('ma_dat_ban', $reservation->ma_dat_ban)->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value)->exists()) {
            throw new HttpException(409, 'This reservation has already been seated.');
        }

        if (! in_array((int) $reservation->ma_ban, $tableIds, true) && $relocationReason === null) {
            throw new HttpException(422, 'The reservation belongs to a different table. Give a reason to seat the guests elsewhere.');
        }

        if (! $this->schedule->withinSeatingWindow($reservation)) {
            if (! $actor->isAdmin()) {
                throw new HttpException(403, sprintf(
                    'Guests can be seated from %s to %s. Ask a manager to approve seating outside this window.',
                    $this->schedule->seatingOpensAt($reservation)->format('H:i'),
                    $this->schedule->holdUntil($reservation)->format('H:i')
                ));
            }
            if ($overrideReason === null) {
                throw new HttpException(422, 'Give a reason for seating outside the reservation window.');
            }
        }

        return $reservation;
    }

    /** @param list<int> $tableIds */
    private function releaseExpiredHold(int $reservationId, array $tableIds, Account $actor): void
    {
        $reservation = Reservation::query()->lockForUpdate()->find($reservationId);
        if (! $reservation || ! in_array((int) $reservation->ma_ban, $tableIds, true)) {
            throw new HttpException(409, 'That reservation is not on the selected table.');
        }

        $this->desk->markNoShow($reservation, $actor, 'Nhường bàn sau khi hết hạn giữ chỗ');
    }

    private function lockActive(ServiceSession $session): ServiceSession
    {
        $locked = ServiceSession::query()->with('tables')->lockForUpdate()->findOrFail($session->ma_phien);
        if (! in_array($locked->trang_thai, self::ACTIVE_STATUSES, true)) {
            throw new HttpException(409, 'This session is already closed.');
        }

        return $locked;
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
