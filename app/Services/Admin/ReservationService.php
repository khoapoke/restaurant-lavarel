<?php

namespace App\Services\Admin;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\OpeningHours;
use App\Services\Shared\ReservationSchedule;
use App\Services\Shared\TableStatusSynchronizer;
use App\Services\Staff\ReservationDeskService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Quản trị đặt bàn (ADR-025). Ghi chú nội bộ tách khỏi ghi chú của khách; hủy/từ chối/không
 * đến cần lý do; COMPLETED chỉ đến từ việc đóng phiên phục vụ.
 */
class ReservationService
{
    private const REASON_REQUIRED = [ReservationStatus::REJECTED, ReservationStatus::CANCELLED];

    public function __construct(
        private readonly TableStatusSynchronizer $tableStatusSynchronizer,
        private readonly ReservationSchedule $schedule,
        private readonly OpeningHours $hours,
        private readonly ReservationDeskService $desk,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $filters */
    public function getReservations(array $filters = []): LengthAwarePaginator
    {
        $query = Reservation::query()->with(['account.userProfile', 'diningTable', 'handledBy.userProfile', 'extendedBy.userProfile', 'serviceSessions.tables']);

        if (! empty($filters['status'])) {
            $query->where('trang_thai', $this->statusFromName((string) $filters['status'])->value);
        }

        if (! empty($filters['date'])) {
            $query->whereDate('thoi_gian', (string) $filters['date']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($nested) use ($keyword): void {
                $nested->whereHas('account', function ($accountQuery) use ($keyword): void {
                    $accountQuery->where('ten_dang_nhap', 'like', "%{$keyword}%")
                        ->orWhereHas('userProfile', fn ($profileQuery) => $profileQuery->where('ho_ten', 'like', "%{$keyword}%"));
                })->orWhereHas('diningTable', fn ($tableQuery) => $tableQuery->where('ten_ban', 'like', "%{$keyword}%"));
            });
        }

        $scope = (string) ($filters['scope'] ?? (empty($filters['date']) ? 'upcoming' : 'all'));
        if ($scope === 'upcoming') {
            $query->where('thoi_gian', '>=', now()->startOfDay());
        } elseif ($scope === 'past') {
            $query->where('thoi_gian', '<', now()->startOfDay());
        }

        $direction = $scope === 'past' ? 'desc' : 'asc';

        return $query->withExists(['serviceSessions as is_seated' => fn ($sessionQuery) => $sessionQuery->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value)])
            ->orderBy('thoi_gian', $direction)
            ->orderBy('ma_dat_ban', $direction)
            ->paginate(min(max((int) ($filters['perPage'] ?? 15), 1), 50));
    }

    public function getReservation(int $reservationId): Reservation
    {
        return Reservation::query()
            ->with(['account.userProfile', 'diningTable', 'handledBy.userProfile', 'extendedBy.userProfile', 'serviceSessions.tables'])
            ->withExists(['serviceSessions as is_seated' => fn ($sessionQuery) => $sessionQuery->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value)])
            ->findOrFail($reservationId);
    }

    public function updateStatus(int $reservationId, string $targetStatus, ?string $reason = null, ?Account $actor = null, ?string $internalNote = null): Reservation
    {
        return DB::transaction(function () use ($reservationId, $targetStatus, $reason, $actor, $internalNote): Reservation {
            $reservation = Reservation::query()
                ->with('diningTable')
                ->lockForUpdate()
                ->findOrFail($reservationId);
            $target = $this->statusFromName($targetStatus);
            $reason = $reason !== null ? trim($reason) : null;

            if ($target === ReservationStatus::COMPLETED) {
                throw new HttpException(409, 'A reservation is completed automatically when its service session closes.');
            }

            if (! $reservation->trang_thai->canTransitionTo($target)) {
                throw new HttpException(409, "Invalid reservation transition from {$reservation->trang_thai->name} to {$target->name}");
            }

            if ($this->schedule->isSeated($reservation)) {
                throw new HttpException(409, 'The guests are already seated. Close the POS session instead');
            }

            if (in_array($target, self::REASON_REQUIRED, true) && ($reason === null || mb_strlen($reason) < 3)) {
                throw new HttpException(422, 'Enter a reason the customer can understand.');
            }

            if ($internalNote) {
                $this->appendInternalNote($reservation, $actor, $internalNote);
            }

            switch ($target) {
                case ReservationStatus::CONFIRMED:
                    $this->assertConfirmable($reservation);
                    $before = ['status' => $reservation->trang_thai->name];
                    $reservation->update(['trang_thai' => ReservationStatus::CONFIRMED, 'xu_ly_luc' => now(), 'ma_nguoi_xu_ly' => $actor?->ma_tai_khoan]);
                    $this->audit->record($actor, 'RESERVATION_CONFIRMED', 'RESERVATION', (int) $reservation->ma_dat_ban, $reason, $before, ['status' => ReservationStatus::CONFIRMED->name]);
                    $this->tableStatusSynchronizer->refreshReservationHold($reservation->diningTable);
                    break;
                case ReservationStatus::NO_SHOW:
                    $this->desk->markNoShow($reservation, $actor, $reason ?: 'Khách không đến');
                    break;
                case ReservationStatus::EXPIRED:
                    if ($reservation->thoi_gian->isFuture()) {
                        throw new HttpException(409, 'A pending reservation expires only after its time has passed.');
                    }
                    $this->desk->close($reservation, ReservationStatus::EXPIRED, $actor, $reason ?: 'Hết hạn chờ xác nhận');
                    break;
                default:
                    $this->desk->close($reservation, $target, $actor, $reason);
            }

            return $this->getReservation($reservationId);
        });
    }

    /**
     * Nhà hàng sửa giờ/bàn/số khách (vd. bàn hỏng phải chuyển) và ghi chú nội bộ.
     *
     * @param  array{reservationTime?: ?string, tableId?: ?int, guestCount?: ?int, internalNote?: ?string, reason: string}  $data
     */
    public function edit(int $reservationId, array $data, Account $actor): Reservation
    {
        return DB::transaction(function () use ($reservationId, $data, $actor): Reservation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservationId);

            if (! $reservation->trang_thai->isActive()) {
                throw new HttpException(409, 'Only pending or confirmed reservations can be edited.');
            }
            if ($this->schedule->isSeated($reservation)) {
                throw new HttpException(409, 'The guests are already seated. Change the service session instead.');
            }

            $time = ! empty($data['reservationTime']) ? Carbon::parse($data['reservationTime']) : $reservation->thoi_gian->copy();
            $guestCount = (int) ($data['guestCount'] ?? $reservation->so_luong_khach);
            $tableId = (int) ($data['tableId'] ?? $reservation->ma_ban);
            $previousTableId = (int) $reservation->ma_ban;

            if (! $this->hours->acceptsReservationAt($time)) {
                throw new HttpException(422, sprintf('Arrival time must be between %s and %s.', config('restaurant.hours.opens_at'), config('restaurant.hours.last_reservation_at')));
            }
            if ($time->isPast() && ! $time->equalTo($reservation->thoi_gian)) {
                throw new HttpException(422, 'Choose a time in the future.');
            }

            $table = DiningTable::query()->lockForUpdate()->findOrFail($tableId);
            if ($table->trang_thai === TableStatus::MAINTENANCE) {
                throw new HttpException(409, 'The selected table is under maintenance');
            }
            if ($guestCount > $table->suc_chua) {
                throw new HttpException(422, "Guest count exceeds this table's capacity of {$table->suc_chua}");
            }

            $end = $time->copy()->addMinutes($this->schedule->diningMinutes($guestCount));
            $conflict = $this->schedule->conflictingReservations($tableId, $time, $end, [(int) $reservation->ma_dat_ban])->first();
            if ($conflict) {
                throw new HttpException(409, "The table already has reservation #{$conflict->ma_dat_ban} at {$conflict->thoi_gian->format('H:i')}.");
            }
            if ($this->schedule->conflictingSession($tableId, $time, $end)) {
                throw new HttpException(409, 'The table is still in use around that time.');
            }

            $before = $this->snapshot($reservation);
            $reservation->update([
                'thoi_gian' => $time,
                'so_luong_khach' => $guestCount,
                'ma_ban' => $tableId,
                'gia_han_den' => $time->equalTo($reservation->thoi_gian) ? $reservation->gia_han_den : null,
            ]);
            if (! empty($data['internalNote'])) {
                $this->appendInternalNote($reservation, $actor, (string) $data['internalNote']);
            }
            $this->audit->record($actor, 'RESERVATION_EDITED', 'RESERVATION', (int) $reservation->ma_dat_ban, $data['reason'], $before, $this->snapshot($reservation->fresh()));

            $this->tableStatusSynchronizer->refreshReservationHold($table);
            if ($previousTableId !== $tableId) {
                $this->tableStatusSynchronizer->refreshReservationHold(DiningTable::query()->lockForUpdate()->findOrFail($previousTableId));
            }

            return $this->getReservation($reservationId);
        });
    }

    private function assertConfirmable(Reservation $reservation): void
    {
        // PENDING hết hạn muộn nhất tại giờ hẹn, kể cả khi lệnh quét chưa chạy.
        if (! $reservation->thoi_gian->isFuture()) {
            throw new HttpException(409, 'This reservation time has already passed');
        }

        if ($reservation->diningTable->trang_thai === TableStatus::MAINTENANCE) {
            throw new HttpException(409, 'The selected table is under maintenance');
        }

        $end = $this->schedule->end($reservation);
        $overlap = $this->schedule->conflictingReservations((int) $reservation->ma_ban, $reservation->thoi_gian, $end, [(int) $reservation->ma_dat_ban])
            ->first(fn (Reservation $other): bool => $other->trang_thai === ReservationStatus::CONFIRMED);
        if ($overlap) {
            throw new HttpException(409, 'This table has an overlapping confirmed reservation');
        }

        $session = $this->schedule->conflictingSession((int) $reservation->ma_ban, $reservation->thoi_gian, $end);
        if ($session) {
            throw new HttpException(409, sprintf('The table is expected to be in use until %s. Move the booking to another table first.', $this->schedule->sessionBusyUntil($session)->format('H:i')));
        }
    }

    private function appendInternalNote(Reservation $reservation, ?Account $actor, string $note): void
    {
        $line = sprintf('[%s %s] %s', now()->format('d/m H:i'), $actor?->displayName() ?? 'Hệ thống', trim($note));
        $reservation->update(['ghi_chu_noi_bo' => trim(trim((string) $reservation->ghi_chu_noi_bo)."\n".$line)]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Reservation $reservation): array
    {
        return [
            'reservationTime' => $reservation->thoi_gian?->format('Y-m-d H:i'),
            'guestCount' => (int) $reservation->so_luong_khach,
            'tableId' => (int) $reservation->ma_ban,
            'status' => $reservation->trang_thai->name,
        ];
    }

    private function statusFromName(string $input): ReservationStatus
    {
        foreach (ReservationStatus::cases() as $status) {
            if ($status->name === $input || $status->value === $input) {
                return $status;
            }
        }

        throw new HttpException(422, 'Unknown reservation status');
    }
}
