<?php

namespace App\Services\Customer;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    /**
     * Find available tables by date, time, and guest count (BKG-01).
     *
     * @param string $date
     * @param string $time
     * @param int $guestCount
     * @return Collection
     */
    public function findAvailableTables(string $date, string $time, int $guestCount): Collection
    {
        $reservationTime = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}");
        $startTime = $reservationTime->copy()->subMinutes(119);
        $endTime = $reservationTime->copy()->addMinutes(119);

        $activeStatuses = [
            ReservationStatus::PENDING->value,
            ReservationStatus::CONFIRMED->value,
        ];

        return DiningTable::where('suc_chua', '>=', $guestCount)
            ->where('trang_thai', '!=', TableStatus::MAINTENANCE)
            ->whereDoesntHave('reservations', function ($query) use ($startTime, $endTime, $activeStatuses) {
                $query->whereIn('trang_thai', $activeStatuses)
                    ->whereBetween('thoi_gian', [$startTime, $endTime]);
            })
            ->orderBy('suc_chua')
            ->orderBy('ma_ban')
            ->get();
    }

    /**
     * Get paginated reservation history for customer.
     *
     * @param int $accountId
     * @param array<string, mixed> $params
     * @return LengthAwarePaginator
     */
    public function getCustomerReservations(int $accountId, array $params = []): LengthAwarePaginator
    {
        $query = Reservation::with('diningTable')
            ->where('ma_tai_khoan', $accountId);

        if (!empty($params['status'])) {
            $statusInput = trim($params['status']);
            $matchedStatus = null;
            foreach (ReservationStatus::cases() as $case) {
                if ($case->name === $statusInput || $case->value === $statusInput) {
                    $matchedStatus = $case;
                    break;
                }
            }
            if ($matchedStatus) {
                $query->where('trang_thai', $matchedStatus->value);
            }
        }

        $query->orderByDesc('thoi_gian')->orderByDesc('ma_dat_ban');

        $perPage = (int) ($params['perPage'] ?? 10);
        return $query->paginate($perPage);
    }

    /**
     * Get details of a customer's reservation.
     *
     * @param int $accountId
     * @param int $reservationId
     * @return Reservation
     * @throws HttpException
     */
    public function getCustomerReservation(int $accountId, int $reservationId): Reservation
    {
        $reservation = Reservation::with('diningTable')
            ->where('ma_dat_ban', $reservationId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        return $reservation;
    }

    /**
     * Create a new reservation for customer (BKG-02).
     *
     * @param int $accountId
     * @param array<string, mixed> $data
     * @return Reservation
     * @throws HttpException
     */
    public function createReservation(int $accountId, array $data): Reservation
    {
        $reservationTime = Carbon::parse($data['reservationTime']);
        $tableId = (int) $data['tableId'];
        $guestCount = (int) $data['guestCount'];
        $note = $data['note'] ?? null;

        // 1. Kiểm tra bàn tồn tại và không trong trạng thái bảo trì
        $table = DiningTable::find($tableId);
        if (!$table) {
            throw new HttpException(404, 'Selected table not found');
        }
        if ($table->trang_thai === TableStatus::MAINTENANCE) {
            throw new HttpException(400, 'Selected table is currently under maintenance');
        }

        // 2. Kiểm tra sức chứa bàn
        if ($guestCount > $table->suc_chua) {
            throw new HttpException(422, "Guest count ({$guestCount}) exceeds table capacity ({$table->suc_chua})");
        }

        // 3. Kiểm tra thời gian báo trước (Chính sách 1.2.5.a)
        if (now()->diffInMinutes($reservationTime, false) < 30) {
            throw new HttpException(400, 'Reservations must be made at least 30 minutes in advance.');
        }

        if ($this->hasSpecialRequest($note) && now()->diffInMinutes($reservationTime, false) < 120) {
            throw new HttpException(400, 'Special requests require at least 2 hours advance notice.');
        }

        // 4. Kiểm tra trùng khung giờ (±119 phút)
        $startTime = $reservationTime->copy()->subMinutes(119);
        $endTime = $reservationTime->copy()->addMinutes(119);

        $hasConflict = Reservation::where('ma_ban', $tableId)
            ->whereIn('trang_thai', [
                ReservationStatus::PENDING->value,
                ReservationStatus::CONFIRMED->value,
            ])
            ->whereBetween('thoi_gian', [$startTime, $endTime])
            ->exists();

        if ($hasConflict) {
            throw new HttpException(409, 'Table is already reserved for the selected time slot');
        }

        // 5. Tạo lượt đặt bàn
        $reservation = Reservation::create([
            'thoi_gian' => $reservationTime,
            'so_luong_khach' => $guestCount,
            'trang_thai' => ReservationStatus::PENDING,
            'ghi_chu' => $note,
            'ma_tai_khoan' => $accountId,
            'ma_ban' => $tableId,
        ]);

        return $reservation->load('diningTable');
    }

    /**
     * Update an existing reservation (BKG-03).
     *
     * @param int $accountId
     * @param int $reservationId
     * @param array<string, mixed> $data
     * @return Reservation
     * @throws HttpException
     */
    public function updateReservation(int $accountId, int $reservationId, array $data): Reservation
    {
        $reservation = Reservation::with('diningTable')
            ->where('ma_dat_ban', $reservationId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        if (in_array($reservation->trang_thai, [ReservationStatus::CANCELLED, ReservationStatus::REJECTED, ReservationStatus::COMPLETED])) {
            throw new HttpException(409, 'Cannot modify a cancelled or completed reservation');
        }

        // Kiểm tra thời gian sửa trước giờ hẹn ít nhất 60 phút (Chính sách 1.2.5.b)
        $minutesUntilArrival = now()->diffInMinutes($reservation->thoi_gian, false);
        if ($minutesUntilArrival < 60) {
            throw new HttpException(409, 'Cannot modify or cancel reservation less than 60 minutes before scheduled time');
        }

        $newTableId = isset($data['tableId']) ? (int) $data['tableId'] : (int) $reservation->ma_ban;
        $newTime = isset($data['reservationTime']) ? Carbon::parse($data['reservationTime']) : $reservation->thoi_gian;
        $newGuestCount = isset($data['guestCount']) ? (int) $data['guestCount'] : (int) $reservation->so_luong_khach;
        $newNote = array_key_exists('note', $data) ? $data['note'] : $reservation->ghi_chu;

        // Kiểm tra bàn
        $table = DiningTable::find($newTableId);
        if (!$table) {
            throw new HttpException(404, 'Selected table not found');
        }
        if ($table->trang_thai === TableStatus::MAINTENANCE) {
            throw new HttpException(400, 'Selected table is currently under maintenance');
        }

        // Kiểm tra sức chứa
        if ($newGuestCount > $table->suc_chua) {
            throw new HttpException(422, "Guest count ({$newGuestCount}) exceeds table capacity ({$table->suc_chua})");
        }

        // Kiểm tra thời gian báo trước
        if (isset($data['reservationTime']) && now()->diffInMinutes($newTime, false) < 30) {
            throw new HttpException(400, 'Reservations must be made at least 30 minutes in advance.');
        }

        if ($this->hasSpecialRequest($newNote) && now()->diffInMinutes($newTime, false) < 120) {
            throw new HttpException(400, 'Special requests require at least 2 hours advance notice.');
        }

        // Kiểm tra trùng giờ đặt bàn (loại trừ chính bản ghi đang sửa)
        $startTime = $newTime->copy()->subMinutes(119);
        $endTime = $newTime->copy()->addMinutes(119);

        $hasConflict = Reservation::where('ma_ban', $newTableId)
            ->where('ma_dat_ban', '!=', $reservation->ma_dat_ban)
            ->whereIn('trang_thai', [
                ReservationStatus::PENDING->value,
                ReservationStatus::CONFIRMED->value,
            ])
            ->whereBetween('thoi_gian', [$startTime, $endTime])
            ->exists();

        if ($hasConflict) {
            throw new HttpException(409, 'Table is already reserved for the selected time slot');
        }

        $reservation->thoi_gian = $newTime;
        $reservation->ma_ban = $newTableId;
        $reservation->so_luong_khach = $newGuestCount;
        $reservation->ghi_chu = $newNote;
        $reservation->trang_thai = ReservationStatus::PENDING;
        $reservation->save();

        return $reservation->load('diningTable');
    }

    /**
     * Cancel customer's reservation (BKG-04).
     *
     * @param int $accountId
     * @param int $reservationId
     * @return Reservation
     * @throws HttpException
     */
    public function cancelReservation(int $accountId, int $reservationId): Reservation
    {
        $reservation = Reservation::where('ma_dat_ban', $reservationId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        if ($reservation->trang_thai === ReservationStatus::CANCELLED) {
            throw new HttpException(409, 'Reservation is already cancelled');
        }

        if ($reservation->trang_thai === ReservationStatus::COMPLETED) {
            throw new HttpException(409, 'Cannot cancel a completed reservation');
        }

        // Kiểm tra hủy trước giờ hẹn ít nhất 60 phút (Chính sách 1.2.5.b)
        $minutesUntilArrival = now()->diffInMinutes($reservation->thoi_gian, false);
        if ($minutesUntilArrival < 60) {
            throw new HttpException(409, 'Cannot modify or cancel reservation less than 60 minutes before scheduled time');
        }

        $reservation->trang_thai = ReservationStatus::CANCELLED;
        $reservation->save();

        return $reservation;
    }

    /**
     * Check if the reservation note contains special request keywords.
     *
     * @param string|null $note
     * @return bool
     */
    protected function hasSpecialRequest(?string $note): bool
    {
        if (empty($note)) {
            return false;
        }

        $noteLower = mb_strtolower($note, 'UTF-8');
        $specialKeywords = ['sinh nhật', 'phòng riêng', 'vip', 'chay', 'trang trí'];

        foreach ($specialKeywords as $keyword) {
            if (str_contains($noteLower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
