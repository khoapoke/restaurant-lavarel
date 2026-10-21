<?php

namespace App\Services\Shared;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Reservation;

/**
 * Keeps BAN_AN.trang_thai consistent with confirmed reservations.
 *
 * A table is RESERVED while it holds a confirmed reservation later today (or one that
 * started less than a dining slot ago and has not been seated yet). OCCUPIED, CLEANING
 * and MAINTENANCE are operational states and are never overwritten here.
 */
class TableStatusSynchronizer
{
    /** Length of a standard dining slot used for overlap checks, in minutes. */
    public const SLOT_MINUTES = 119;

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
        return Reservation::query()
            ->where('ma_ban', $tableId)
            ->where('trang_thai', ReservationStatus::CONFIRMED->value)
            ->whereBetween('thoi_gian', [now()->subMinutes(self::SLOT_MINUTES), now()->endOfDay()])
            ->exists();
    }

    /**
     * Confirmed reservation whose dining slot overlaps a walk-in seated right now.
     */
    public function conflictingReservation(int $tableId, ?int $ignoredReservationId = null): ?Reservation
    {
        return Reservation::query()
            ->where('ma_ban', $tableId)
            ->where('trang_thai', ReservationStatus::CONFIRMED->value)
            ->whereBetween('thoi_gian', [now()->subMinutes(self::SLOT_MINUTES), now()->addMinutes(self::SLOT_MINUTES)])
            ->when($ignoredReservationId !== null, fn ($query) => $query->where('ma_dat_ban', '!=', $ignoredReservationId))
            ->orderBy('thoi_gian')
            ->first();
    }
}
