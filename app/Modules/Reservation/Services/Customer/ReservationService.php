<?php

namespace App\Modules\Reservation\Services\Customer;

use App\Modules\Core\Enums\ReservationStatus;
use App\Modules\Core\Enums\TableStatus;
use App\Modules\Reservation\Models\DiningTable;
use App\Modules\Reservation\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    /**
     * Check if a given time falls into peak hours (11:30-13:30 or 18:00-20:30).
     *
     * @param Carbon $time
     * @return bool
     */
    public function isPeakHour(Carbon $time): bool
    {
        $timeStr = $time->format('H:i');
        $isLunchPeak = ($timeStr >= '11:30' && $timeStr <= '13:30');
        $isDinnerPeak = ($timeStr >= '18:00' && $timeStr <= '20:30');

        return $isLunchPeak || $isDinnerPeak;
    }

    /**
     * Calculate deposit amount based on guest count and peak hour.
     * Rule: >= 6 guests in peak hours = 50,000 VND / guest. Otherwise 0.
     *
     * @param int $guestCount
     * @param Carbon $time
     * @return float
     */
    public function calculateDeposit(int $guestCount, Carbon $time): float
    {
        if ($guestCount >= 6 && $this->isPeakHour($time)) {
            return (float) ($guestCount * 50000);
        }

        return 0.0;
    }

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

        return DiningTable::where('suc_chua', '>=', $guestCount)
            ->where('trang_thai', '!=', TableStatus::MAINTENANCE)
            ->whereDoesntHave('reservations', function ($query) use ($startTime, $endTime) {
                $query->where(function ($subQ) {
                    $subQ->whereIn('trang_thai', [
                        ReservationStatus::PENDING->value,
                        ReservationStatus::CONFIRMED->value,
                        ReservationStatus::OCCUPIED->value,
                    ])->orWhere(function ($depositQ) {
                        $depositQ->where('trang_thai', ReservationStatus::PENDING_DEPOSIT->value)
                            ->where('han_chot_coc', '>', now());
                    });
                })->whereBetween('thoi_gian', [$startTime, $endTime]);
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
        $tableId = (int) $data['tableId'];
        $lock = Cache::lock("lock:reservation:table:{$tableId}", 5);

        try {
            return $lock->block(3, function () use ($accountId, $data, $tableId) {
                $reservationTime = Carbon::parse($data['reservationTime']);
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
                    ->where(function ($q) {
                        $q->whereIn('trang_thai', [
                            ReservationStatus::PENDING->value,
                            ReservationStatus::CONFIRMED->value,
                            ReservationStatus::OCCUPIED->value,
                        ])->orWhere(function ($depositQ) {
                            $depositQ->where('trang_thai', ReservationStatus::PENDING_DEPOSIT->value)
                                ->where('han_chot_coc', '>', now());
                        });
                    })
                    ->whereBetween('thoi_gian', [$startTime, $endTime])
                    ->exists();

                if ($hasConflict) {
                    throw new HttpException(409, 'Table is already reserved for the selected time slot');
                }

                // 5. Tính tiền cọc & thời hạn giữ bàn 15 phút
                $deposit = $this->calculateDeposit($guestCount, $reservationTime);
                $initialStatus = $deposit > 0 ? ReservationStatus::PENDING_DEPOSIT : ReservationStatus::PENDING;
                $depositDeadline = $deposit > 0 ? now()->addMinutes(15) : null;

                // 6. Tạo lượt đặt bàn
                $reservation = Reservation::create([
                    'thoi_gian' => $reservationTime,
                    'so_luong_khach' => $guestCount,
                    'trang_thai' => $initialStatus,
                    'ghi_chu' => $note,
                    'ma_tai_khoan' => $accountId,
                    'ma_ban' => $tableId,
                    'tien_coc' => $deposit,
                    'da_thanh_toan_coc' => false,
                    'han_chot_coc' => $depositDeadline,
                ]);

                return $reservation->load('diningTable');
            });
        } catch (LockTimeoutException $e) {
            throw new HttpException(409, 'Table is currently being reserved by another request. Please try again.');
        }
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

        if (in_array($reservation->trang_thai, [
            ReservationStatus::CANCELLED,
            ReservationStatus::REJECTED,
            ReservationStatus::COMPLETED,
            ReservationStatus::OCCUPIED,
        ])) {
            throw new HttpException(409, 'Cannot modify a cancelled, completed or occupied reservation');
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
            ->where(function ($q) {
                $q->whereIn('trang_thai', [
                    ReservationStatus::PENDING->value,
                    ReservationStatus::CONFIRMED->value,
                    ReservationStatus::OCCUPIED->value,
                ])->orWhere(function ($depositQ) {
                    $depositQ->where('trang_thai', ReservationStatus::PENDING_DEPOSIT->value)
                        ->where('han_chot_coc', '>', now());
                });
            })
            ->whereBetween('thoi_gian', [$startTime, $endTime])
            ->exists();

        if ($hasConflict) {
            throw new HttpException(409, 'Table is already reserved for the selected time slot');
        }

        $reservation->thoi_gian = $newTime;
        $reservation->ma_ban = $newTableId;
        $reservation->so_luong_khach = $newGuestCount;
        $reservation->ghi_chu = $newNote;

        // Nếu chưa cọc, tính lại tiền cọc và hạn chót theo giờ/số lượng mới
        if (!$reservation->da_thanh_toan_coc) {
            $deposit = $this->calculateDeposit($newGuestCount, $newTime);
            $reservation->tien_coc = $deposit;
            $reservation->han_chot_coc = $deposit > 0 ? now()->addMinutes(15) : null;
            $reservation->trang_thai = $deposit > 0 ? ReservationStatus::PENDING_DEPOSIT : ReservationStatus::PENDING;
        } else {
            $reservation->trang_thai = ReservationStatus::PENDING;
        }

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
        $reservation = Reservation::with('diningTable')
            ->where('ma_dat_ban', $reservationId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        if ($reservation->trang_thai === ReservationStatus::CANCELLED) {
            throw new HttpException(409, 'Reservation is already cancelled');
        }

        if ($reservation->trang_thai === ReservationStatus::COMPLETED || $reservation->trang_thai === ReservationStatus::OCCUPIED) {
            throw new HttpException(409, 'Cannot cancel a completed or occupied reservation');
        }

        // Kiểm tra hủy trước giờ hẹn ít nhất 60 phút (Chính sách 1.2.5.b)
        $minutesUntilArrival = now()->diffInMinutes($reservation->thoi_gian, false);
        if ($minutesUntilArrival < 60) {
            throw new HttpException(409, 'Cannot modify or cancel reservation less than 60 minutes before scheduled time');
        }

        $table = $reservation->diningTable;
        if ($table && $table->trang_thai === TableStatus::RESERVED) {
            $hasOtherConfirmed = Reservation::where('ma_ban', $reservation->ma_ban)
                ->where('ma_dat_ban', '!=', $reservation->ma_dat_ban)
                ->where('trang_thai', ReservationStatus::CONFIRMED->value)
                ->exists();
            if (!$hasOtherConfirmed) {
                $table->trang_thai = TableStatus::AVAILABLE;
                $table->save();
            }
        }

        $reservation->trang_thai = ReservationStatus::CANCELLED;
        $reservation->save();

        return $reservation;
    }

    /**
     * Mock successful deposit payment for customer reservation (BKG-13).
     *
     * @param int $accountId
     * @param int $reservationId
     * @return Reservation
     * @throws HttpException
     */
    public function mockDepositSuccess(int $accountId, int $reservationId): Reservation
    {
        $reservation = Reservation::with('diningTable')
            ->where('ma_dat_ban', $reservationId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        if ($reservation->trang_thai !== ReservationStatus::PENDING_DEPOSIT) {
            throw new HttpException(400, 'Reservation does not require deposit or is not pending deposit');
        }

        if ($reservation->han_chot_coc && $reservation->han_chot_coc->isPast()) {
            $reservation->trang_thai = ReservationStatus::CANCELLED;
            $reservation->save();
            throw new HttpException(400, 'Deposit payment window has expired. Reservation is cancelled.');
        }

        $reservation->da_thanh_toan_coc = true;
        $reservation->trang_thai = ReservationStatus::CONFIRMED;
        $reservation->save();

        $table = $reservation->diningTable;
        if ($table && $table->trang_thai === TableStatus::AVAILABLE) {
            $table->trang_thai = TableStatus::RESERVED;
            $table->save();
        }

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

