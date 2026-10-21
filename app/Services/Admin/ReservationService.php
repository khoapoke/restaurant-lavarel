<?php

namespace App\Services\Admin;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Reservation;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    public function __construct(private readonly TableStatusSynchronizer $tableStatusSynchronizer) {}

    /** @param array<string, mixed> $filters */
    public function getReservations(array $filters = []): LengthAwarePaginator
    {
        $query = Reservation::query()->with(['account.userProfile', 'diningTable']);

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

    public function updateStatus(int $reservationId, string $targetStatus, ?string $adminNote = null): Reservation
    {
        return DB::transaction(function () use ($reservationId, $targetStatus, $adminNote): Reservation {
            $reservation = Reservation::query()
                ->with('diningTable')
                ->lockForUpdate()
                ->findOrFail($reservationId);
            $target = $this->statusFromName($targetStatus);

            if (! $reservation->trang_thai->canTransitionTo($target)) {
                throw new HttpException(409, "Invalid reservation transition from {$reservation->trang_thai->name} to {$target->name}");
            }

            $hasActiveSession = $reservation->serviceSessions()
                ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
                ->exists();
            if ($hasActiveSession && $target !== ReservationStatus::COMPLETED) {
                throw new HttpException(409, 'The guests are already seated. Close the POS session instead');
            }

            if ($target === ReservationStatus::CONFIRMED) {
                if ($reservation->thoi_gian->lt(now()->subMinutes(TableStatusSynchronizer::SLOT_MINUTES))) {
                    throw new HttpException(409, 'This reservation time has already passed');
                }

                if ($reservation->diningTable->trang_thai === TableStatus::MAINTENANCE) {
                    throw new HttpException(409, 'The selected table is under maintenance');
                }

                $overlapExists = Reservation::query()
                    ->where('ma_ban', $reservation->ma_ban)
                    ->where('ma_dat_ban', '!=', $reservation->ma_dat_ban)
                    ->where('trang_thai', ReservationStatus::CONFIRMED->value)
                    ->whereBetween('thoi_gian', [
                        $reservation->thoi_gian->copy()->subMinutes(119),
                        $reservation->thoi_gian->copy()->addMinutes(119),
                    ])->exists();

                if ($overlapExists) {
                    throw new HttpException(409, 'This table has an overlapping confirmed reservation');
                }
            }

            if ($adminNote) {
                $reservation->ghi_chu = trim((string) $reservation->ghi_chu."\n[ADMIN] ".$adminNote);
            }
            $reservation->trang_thai = $target;
            $reservation->save();

            $this->tableStatusSynchronizer->refreshReservationHold($reservation->diningTable);

            return $reservation->fresh()->load(['account.userProfile', 'diningTable']);
        });
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
