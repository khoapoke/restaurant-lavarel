<?php

namespace App\Services\Customer;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    /** @param array{date: string, time: string, guestCount: int|string} $data */
    public function getAvailableTables(array $data): Collection
    {
        $reservationTime = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}");
        $this->assertAdvanceNotice($reservationTime, null);

        return DiningTable::query()
            ->where('suc_chua', '>=', (int) $data['guestCount'])
            ->where('trang_thai', '!=', TableStatus::MAINTENANCE->value)
            ->whereDoesntHave('reservations', function ($query) use ($reservationTime): void {
                $query->whereIn('trang_thai', [
                    ReservationStatus::PENDING->value,
                    ReservationStatus::CONFIRMED->value,
                ])->whereBetween('thoi_gian', [
                    $reservationTime->copy()->subMinutes(119),
                    $reservationTime->copy()->addMinutes(119),
                ]);
            })
            ->orderBy('suc_chua')
            ->orderBy('ten_ban')
            ->get();
    }

    public function getCustomerReservations(int $accountId, int $perPage = 10): LengthAwarePaginator
    {
        return Reservation::query()
            ->with('diningTable')
            ->where('ma_tai_khoan', $accountId)
            ->orderByDesc('thoi_gian')
            ->orderByDesc('ma_dat_ban')
            ->paginate(min(max($perPage, 1), 50));
    }

    /** @param array<string, mixed> $data */
    public function create(int $accountId, array $data): Reservation
    {
        return DB::transaction(function () use ($accountId, $data): Reservation {
            $reservationTime = Carbon::createFromFormat('Y-m-d H:i:s', $data['reservationTime']);
            $note = $data['note'] ?? null;
            $this->assertAdvanceNotice($reservationTime, $note);

            $table = DiningTable::query()->lockForUpdate()->findOrFail((int) $data['tableId']);
            $this->assertTableCanBeBooked($table, (int) $data['guestCount']);
            $this->assertNoOverlap($table->ma_ban, $reservationTime);

            $reservation = Reservation::create([
                'thoi_gian' => $reservationTime,
                'so_luong_khach' => (int) $data['guestCount'],
                'trang_thai' => ReservationStatus::PENDING,
                'ghi_chu' => $note,
                'ma_tai_khoan' => $accountId,
                'ma_ban' => $table->ma_ban,
            ]);

            return $reservation->load('diningTable');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $accountId, int $reservationId, array $data): Reservation
    {
        return DB::transaction(function () use ($accountId, $reservationId, $data): Reservation {
            $reservation = Reservation::query()
                ->where('ma_tai_khoan', $accountId)
                ->lockForUpdate()
                ->find($reservationId);

            if (! $reservation) {
                throw new HttpException(404, 'Reservation not found');
            }

            $this->assertCustomerCanModify($reservation);

            $reservationTime = isset($data['reservationTime'])
                ? Carbon::createFromFormat('Y-m-d H:i:s', $data['reservationTime'])
                : $reservation->thoi_gian->copy();
            $guestCount = (int) ($data['guestCount'] ?? $reservation->so_luong_khach);
            $tableId = (int) ($data['tableId'] ?? $reservation->ma_ban);
            $note = array_key_exists('note', $data) ? $data['note'] : $reservation->ghi_chu;

            $this->assertAdvanceNotice($reservationTime, $note);
            $table = DiningTable::query()->lockForUpdate()->findOrFail($tableId);
            $this->assertTableCanBeBooked($table, $guestCount);
            $this->assertNoOverlap($tableId, $reservationTime, $reservation->ma_dat_ban);

            $reservation->update([
                'thoi_gian' => $reservationTime,
                'so_luong_khach' => $guestCount,
                'ghi_chu' => $note,
                'ma_ban' => $tableId,
            ]);

            return $reservation->fresh()->load('diningTable');
        });
    }

    public function cancel(int $accountId, int $reservationId): Reservation
    {
        return DB::transaction(function () use ($accountId, $reservationId): Reservation {
            $reservation = Reservation::query()
                ->with('diningTable')
                ->where('ma_tai_khoan', $accountId)
                ->lockForUpdate()
                ->find($reservationId);

            if (! $reservation) {
                throw new HttpException(404, 'Reservation not found');
            }

            $this->assertCustomerCanModify($reservation);
            $wasConfirmed = $reservation->trang_thai === ReservationStatus::CONFIRMED;
            $reservation->update(['trang_thai' => ReservationStatus::CANCELLED]);

            if ($wasConfirmed && $reservation->diningTable?->trang_thai === TableStatus::RESERVED) {
                $reservation->diningTable->update(['trang_thai' => TableStatus::AVAILABLE]);
            }

            return $reservation->fresh()->load('diningTable');
        });
    }

    private function assertCustomerCanModify(Reservation $reservation): void
    {
        if (! $reservation->trang_thai->isActive()) {
            throw new HttpException(409, 'Only pending or confirmed reservations can be modified');
        }

        if (now()->diffInMinutes($reservation->thoi_gian, false) < 60) {
            throw new HttpException(409, 'Reservations can only be modified or cancelled at least 60 minutes before arrival');
        }
    }

    private function assertAdvanceNotice(Carbon $reservationTime, ?string $note): void
    {
        $requiredMinutes = $this->hasSpecialRequest($note) ? 120 : 30;

        if (now()->diffInMinutes($reservationTime, false) < $requiredMinutes) {
            throw new HttpException(400, $requiredMinutes === 120
                ? 'Special requests require at least 2 hours advance notice'
                : 'Reservations require at least 30 minutes advance notice');
        }
    }

    private function hasSpecialRequest(?string $note): bool
    {
        $normalizedNote = Str::lower($note ?? '');

        return collect(['sinh nhật', 'phòng riêng', 'vip', 'chay', 'trang trí'])
            ->contains(fn (string $keyword): bool => Str::contains($normalizedNote, $keyword));
    }

    private function assertTableCanBeBooked(DiningTable $table, int $guestCount): void
    {
        if ($table->trang_thai === TableStatus::MAINTENANCE) {
            throw new HttpException(409, 'This table is under maintenance');
        }

        if ($guestCount > $table->suc_chua) {
            throw new HttpException(422, "Guest count exceeds this table's capacity of {$table->suc_chua}");
        }
    }

    private function assertNoOverlap(int $tableId, Carbon $reservationTime, ?int $ignoredReservationId = null): void
    {
        $query = Reservation::query()
            ->where('ma_ban', $tableId)
            ->whereIn('trang_thai', [ReservationStatus::PENDING->value, ReservationStatus::CONFIRMED->value])
            ->whereBetween('thoi_gian', [
                $reservationTime->copy()->subMinutes(119),
                $reservationTime->copy()->addMinutes(119),
            ]);

        if ($ignoredReservationId !== null) {
            $query->where('ma_dat_ban', '!=', $ignoredReservationId);
        }

        if ($query->exists()) {
            throw new HttpException(409, 'This table already has an overlapping reservation');
        }
    }
}
