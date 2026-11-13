<?php

namespace App\Modules\Reservation\Services\Admin;

use App\Modules\Core\Enums\ReservationStatus;
use App\Modules\Core\Enums\TableStatus;
use App\Modules\Reservation\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    private const CACHE_KEY_TIME_SLOTS = 'restaurant_time_slots_config';

    /**
     * Get configured time slots for reservations (BKG-07).
     *
     * @return array<string, mixed>
     */
    public function getTimeSlots(): array
    {
        return Cache::get(self::CACHE_KEY_TIME_SLOTS, [
            'openingTime' => '08:00',
            'closingTime' => '22:00',
            'slotDurationMinutes' => 120,
            'advanceNoticeMinutes' => 30,
            'specialRequestNoticeMinutes' => 120,
            'timeSlots' => [
                '09:00', '11:00', '13:00', '17:00', '19:00', '21:00'
            ],
        ]);
    }

    /**
     * Update configured time slots for reservations (BKG-07).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTimeSlots(array $data): array
    {
        $current = $this->getTimeSlots();

        $updated = [
            'openingTime' => $data['openingTime'] ?? $current['openingTime'],
            'closingTime' => $data['closingTime'] ?? $current['closingTime'],
            'slotDurationMinutes' => isset($data['slotDurationMinutes']) ? (int) $data['slotDurationMinutes'] : $current['slotDurationMinutes'],
            'advanceNoticeMinutes' => isset($data['advanceNoticeMinutes']) ? (int) $data['advanceNoticeMinutes'] : $current['advanceNoticeMinutes'],
            'specialRequestNoticeMinutes' => isset($data['specialRequestNoticeMinutes']) ? (int) $data['specialRequestNoticeMinutes'] : $current['specialRequestNoticeMinutes'],
            'timeSlots' => $data['timeSlots'] ?? $current['timeSlots'],
        ];

        Cache::forever(self::CACHE_KEY_TIME_SLOTS, $updated);

        return $updated;
    }

    /**
     * Get paginated reservations list with search and filters (BKG-08).
     *
     * @param array<string, mixed> $params
     * @return LengthAwarePaginator
     */
    public function getReservations(array $params = []): LengthAwarePaginator
    {
        $query = Reservation::with(['diningTable', 'account.userProfile']);

        // Filter by status
        if (!empty($params['status'])) {
            $statusInput = trim((string) $params['status']);
            $matched = ReservationStatus::fromName($statusInput);
            if ($matched) {
                $query->where('trang_thai', $matched->value);
            }
        }

        // Filter by date (YYYY-MM-DD)
        if (!empty($params['date'])) {
            $query->whereDate('thoi_gian', trim((string) $params['date']));
        }

        // Filter by table ID
        if (!empty($params['tableId'])) {
            $query->where('ma_ban', (int) $params['tableId']);
        }

        // Search by customer name, username, phone or note
        if (!empty($params['search'])) {
            $search = trim((string) $params['search']);
            $query->where(function ($q) use ($search) {
                $q->where('ghi_chu', 'LIKE', "%{$search}%")
                  ->orWhereHas('account', function ($sub) use ($search) {
                      $sub->where('ten_dang_nhap', 'LIKE', "%{$search}%")
                          ->orWhereHas('userProfile', function ($pSub) use ($search) {
                              $pSub->where('ho_ten', 'LIKE', "%{$search}%")
                                   ->orWhere('so_dien_thoai', 'LIKE', "%{$search}%");
                          });
                  });
            });
        }

        $query->orderByDesc('thoi_gian');

        $perPage = (int) ($params['perPage'] ?? 10);
        return $query->paginate($perPage);
    }

    /**
     * Get reservation detail by ID.
     *
     * @param int $reservationId
     * @return Reservation
     * @throws HttpException
     */
    public function getReservation(int $reservationId): Reservation
    {
        $reservation = Reservation::with(['diningTable', 'account.userProfile'])
            ->where('ma_dat_ban', $reservationId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        return $reservation;
    }

    /**
     * Update reservation status and synchronize table status (BKG-09 -> BKG-12).
     *
     * @param int $reservationId
     * @param string $targetStatusInput
     * @param string|null $adminNote
     * @param int|null $newTableId
     * @param string|null $tableStatusInput
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function updateStatus(
        int $reservationId,
        string $targetStatusInput,
        ?string $adminNote = null,
        ?int $newTableId = null,
        ?string $tableStatusInput = null
    ): array {
        $reservation = Reservation::with('diningTable')
            ->where('ma_dat_ban', $reservationId)
            ->first();

        if (!$reservation) {
            throw new HttpException(404, 'Reservation not found');
        }

        $targetStatus = ReservationStatus::fromName($targetStatusInput);
        if (!$targetStatus) {
            throw new HttpException(400, "Invalid target status: {$targetStatusInput}");
        }

        $oldTable = $reservation->diningTable;
        $oldTableId = $reservation->ma_ban ? (int) $reservation->ma_ban : null;

        // 1. Cập nhật đổi bàn ăn nếu có truyền newTableId
        if ($newTableId && $newTableId !== $oldTableId) {
            $newTable = \App\Modules\Reservation\Models\DiningTable::find($newTableId);
            if (!$newTable) {
                throw new HttpException(404, "Selected table #{$newTableId} not found");
            }
            if ($newTable->trang_thai === TableStatus::MAINTENANCE) {
                throw new HttpException(400, "Selected table #{$newTableId} is currently under maintenance");
            }
            $reservation->ma_ban = $newTableId;
            $table = $newTable;

            // Giải phóng bàn cũ nếu trước đó là RESERVED và không còn đơn CONFIRMED nào khác
            if ($oldTable && $oldTable->trang_thai === TableStatus::RESERVED) {
                $hasOtherConfirmed = Reservation::where('ma_ban', $oldTableId)
                    ->where('ma_dat_ban', '!=', $reservation->ma_dat_ban)
                    ->where('trang_thai', ReservationStatus::CONFIRMED->value)
                    ->exists();
                if (!$hasOtherConfirmed) {
                    $oldTable->trang_thai = TableStatus::AVAILABLE;
                    $oldTable->save();
                }
            }
        } else {
            $table = $oldTable;
        }

        // 2. Khi Admin duyệt đặt bàn (CONFIRMED)
        if ($targetStatus === ReservationStatus::CONFIRMED) {
            if (!$reservation->ma_ban) {
                throw new HttpException(422, 'Vui lòng chọn bàn ăn để duyệt đặt bàn');
            }

            $resTime = Carbon::parse($reservation->thoi_gian);
            $startTime = $resTime->copy()->subMinutes(119);
            $endTime = $resTime->copy()->addMinutes(119);

            // Kiểm tra trùng khung giờ với lượt đặt CONFIRMED khác
            $hasOverlappingConfirmed = Reservation::where('ma_ban', $reservation->ma_ban)
                ->where('ma_dat_ban', '!=', $reservation->ma_dat_ban)
                ->where('trang_thai', ReservationStatus::CONFIRMED->value)
                ->whereBetween('thoi_gian', [$startTime, $endTime])
                ->exists();

            if ($hasOverlappingConfirmed) {
                throw new HttpException(409, "Bàn #{$reservation->ma_ban} đã có lượt đặt bàn khác được xác nhận trong khoảng thời gian này");
            }

            // Đồng bộ trạng thái bàn sang RESERVED hoặc trạng thái chỉ định
            if ($table && $table->trang_thai !== TableStatus::MAINTENANCE) {
                if ($tableStatusInput) {
                    $matched = TableStatus::fromName($tableStatusInput);
                    if ($matched) {
                        $table->trang_thai = $matched;
                    }
                } elseif ($table->trang_thai !== TableStatus::OCCUPIED) {
                    $table->trang_thai = TableStatus::RESERVED;
                }
                $table->save();
            }
        }

        // 3. Cập nhật trạng thái bàn nếu có yêu cầu trực tiếp (ví dụ khách nhận bàn -> OCCUPIED)
        if ($tableStatusInput && $table && $table->trang_thai !== TableStatus::MAINTENANCE) {
            $matchedTableStatus = TableStatus::fromName($tableStatusInput);
            if ($matchedTableStatus) {
                $table->trang_thai = $matchedTableStatus;
                $table->save();
            }
        }

        // 4. BKG-10, BKG-11, BKG-12: Khi chuyển sang REJECTED, CANCELLED, COMPLETED -> Giải phóng bàn về AVAILABLE
        if (in_array($targetStatus, [
            ReservationStatus::REJECTED,
            ReservationStatus::CANCELLED,
            ReservationStatus::COMPLETED,
        ])) {
            if ($table && $table->trang_thai !== TableStatus::MAINTENANCE) {
                // Kiểm tra xem bàn có còn lượt CONFIRMED nào khác không
                $hasOtherActive = Reservation::where('ma_ban', $reservation->ma_ban)
                    ->where('ma_dat_ban', '!=', $reservation->ma_dat_ban)
                    ->where('trang_thai', ReservationStatus::CONFIRMED->value)
                    ->whereDate('thoi_gian', Carbon::parse($reservation->thoi_gian)->toDateString())
                    ->exists();

                if (!$hasOtherActive) {
                    $table->trang_thai = TableStatus::AVAILABLE;
                    $table->save();
                }
            }
        }

        $reservation->trang_thai = $targetStatus;

        if ($adminNote) {
            $reservation->ghi_chu = $reservation->ghi_chu
                ? ($reservation->ghi_chu . ' | Admin: ' . $adminNote)
                : $adminNote;
        }

        $reservation->save();

        // Reload table status
        $tableStatusName = $table ? ($table->trang_thai instanceof TableStatus
            ? $table->trang_thai->name
            : (TableStatus::tryFrom($table->trang_thai)?->name ?? (string) $table->trang_thai)) : 'AVAILABLE';

        return [
            'reservationId' => (int) $reservation->reservationId,
            'status' => $targetStatus->name,
            'tableId' => (int) $reservation->ma_ban,
            'tableStatus' => $tableStatusName,
        ];
    }
}

