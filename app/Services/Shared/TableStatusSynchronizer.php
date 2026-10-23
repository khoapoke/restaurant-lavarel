<?php

namespace App\Services\Shared;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Keeps BAN_AN.trang_thai consistent with confirmed reservations.
 *
 * A table is RESERVED while it holds a confirmed, unseated reservation later today whose
 * hold has not expired (ADR-025). OCCUPIED, CLEANING and MAINTENANCE are operational
 * states and are never overwritten here.
 */
class TableStatusSynchronizer
{
    public function __construct(private readonly ReservationSchedule $schedule) {}

    public function refreshReservationHold(DiningTable $table): DiningTable
    {
        if (! in_array($table->trang_thai, [TableStatus::AVAILABLE, TableStatus::RESERVED], true)) {
            return $table;
        }

        $target = $this->hasReservationHoldToday((int) $table->ma_ban)
            ? TableStatus::RESERVED
            : TableStatus::AVAILABLE;

        if ($table->trang_thai !== $target) {
            $table->update(['trang_thai' => $target]);
        }

        return $table;
    }

    public function hasReservationHoldToday(int $tableId): bool
    {
        return $this->holdsToday($tableId)->isNotEmpty();
    }

    /**
     * Lượt đã xác nhận, chưa nhận bàn, còn trong hạn giữ chỗ, trong hôm nay.
     *
     * @return Collection<int, Reservation>
     */
    public function holdsToday(int $tableId): Collection
    {
        $lookBack = (int) config('restaurant.reservations.grace_minutes', 20) + (int) config('restaurant.reservations.max_extension_minutes', 30);

        return Reservation::query()
            ->where('ma_ban', $tableId)
            ->where('trang_thai', ReservationStatus::CONFIRMED->value)
            ->whereBetween('thoi_gian', [now()->subMinutes($lookBack), now()->endOfDay()])
            ->whereDoesntHave('serviceSessions', fn ($query) => $query->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
            ->orderBy('thoi_gian')
            ->get()
            ->filter(fn (Reservation $reservation): bool => $this->schedule->stillHolds($reservation))
            ->values();
    }
}
