<?php

namespace App\Services\Customer;

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
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Đặt bàn của khách (ADR-025). Bàn trống tính theo khoảng dùng bàn thật + thời gian dọn;
 * giờ đến phải trong giờ nhận đặt; mỗi tài khoản tối đa N lượt tương lai, không chồng giờ.
 */
class ReservationService
{
    /** Customers may change or cancel a booking until this many minutes before arrival. */
    public const MODIFY_CUTOFF_MINUTES = 60;

    public function __construct(
        private readonly TableStatusSynchronizer $tableStatusSynchronizer,
        private readonly ReservationSchedule $schedule,
        private readonly OpeningHours $hours,
        private readonly ReservationDeskService $desk,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{date: string, time: string, guestCount: int|string} $data */
    public function getAvailableTables(array $data): Collection
    {
        $reservationTime = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}");
        $guestCount = (int) $data['guestCount'];
        $this->assertArrival($reservationTime, null);
        $end = $reservationTime->copy()->addMinutes($this->schedule->diningMinutes($guestCount));

        return DiningTable::query()
            ->where('suc_chua', '>=', $guestCount)
            ->where('trang_thai', '!=', TableStatus::MAINTENANCE->value)
            ->orderBy('suc_chua')
            ->orderBy('ten_ban')
            ->get()
            ->filter(fn (DiningTable $table): bool => $this->isFree((int) $table->ma_ban, $reservationTime, $end))
            ->values();
    }

    public function getCustomerReservations(int $accountId, int $perPage = 10): LengthAwarePaginator
    {
        return Reservation::query()
            ->with('diningTable')
            ->withExists(['serviceSessions as is_seated' => fn ($query) => $query->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value)])
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
            $guestCount = (int) $data['guestCount'];
            $this->assertArrival($reservationTime, $note);

            // Khóa tài khoản để hai yêu cầu đồng thời không cùng vượt giới hạn lượt đặt.
            Account::query()->lockForUpdate()->findOrFail($accountId);
            $this->assertAccountLimits($accountId, $reservationTime, $guestCount, null);

            $table = DiningTable::query()->lockForUpdate()->findOrFail((int) $data['tableId']);
            $this->assertTableCanBeBooked($table, $guestCount);
            $this->assertNoOverlap((int) $table->ma_ban, $reservationTime, $guestCount);

            $reservation = Reservation::create([
                'thoi_gian' => $reservationTime,
                'so_luong_khach' => $guestCount,
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

            $this->assertArrival($reservationTime, $note);
            $this->assertAccountLimits($accountId, $reservationTime, $guestCount, (int) $reservation->ma_dat_ban);
            $table = DiningTable::query()->lockForUpdate()->findOrFail($tableId);
            $this->assertTableCanBeBooked($table, $guestCount);
            $this->assertNoOverlap($tableId, $reservationTime, $guestCount, (int) $reservation->ma_dat_ban);

            $previousTableId = (int) $reservation->ma_ban;
            $before = $this->snapshot($reservation);
            $bookingChanged = ! $reservationTime->equalTo($reservation->thoi_gian)
                || $guestCount !== (int) $reservation->so_luong_khach
                || $tableId !== $previousTableId;

            $reservation->update([
                'thoi_gian' => $reservationTime,
                'so_luong_khach' => $guestCount,
                'ghi_chu' => $note,
                'ma_ban' => $tableId,
                // A confirmed booking that moves needs the restaurant to confirm it again.
                'trang_thai' => $bookingChanged ? ReservationStatus::PENDING : $reservation->trang_thai,
                'gia_han_den' => $bookingChanged ? null : $reservation->gia_han_den,
            ]);
            $this->audit->record(Account::find($accountId), 'RESERVATION_UPDATED', 'RESERVATION', (int) $reservation->ma_dat_ban, 'Khách tự cập nhật', $before, $this->snapshot($reservation->fresh()));

            $this->tableStatusSynchronizer->refreshReservationHold($table);
            if ($previousTableId !== $tableId) {
                $this->tableStatusSynchronizer->refreshReservationHold(DiningTable::query()->lockForUpdate()->findOrFail($previousTableId));
            }

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
            $this->desk->close($reservation, ReservationStatus::CANCELLED, Account::find($accountId), 'Khách tự hủy');

            return $reservation->fresh()->load('diningTable');
        });
    }

    private function assertCustomerCanModify(Reservation $reservation): void
    {
        if (! $reservation->trang_thai->isActive() || ! $this->schedule->stillHolds($reservation)) {
            throw new HttpException(409, 'Only pending or confirmed reservations can be modified');
        }

        if ($this->schedule->isSeated($reservation)) {
            throw new HttpException(409, 'You are already seated. Please talk to our staff for any change.');
        }

        if (now()->diffInMinutes($reservation->thoi_gian, false) < self::MODIFY_CUTOFF_MINUTES) {
            throw new HttpException(409, 'Reservations can only be modified or cancelled at least 60 minutes before arrival');
        }
    }

    /** Giờ khách đến: báo trước đủ lâu và nằm trong giờ nhận đặt bàn của ngày đó. */
    private function assertArrival(Carbon $reservationTime, ?string $note): void
    {
        $requiredMinutes = $this->hasSpecialRequest($note)
            ? (int) config('restaurant.reservations.special_request_notice_minutes', 120)
            : (int) config('restaurant.reservations.advance_notice_minutes', 30);

        if (now()->diffInMinutes($reservationTime, false) < $requiredMinutes) {
            throw new HttpException(400, $requiredMinutes >= 120
                ? 'Special requests require at least 2 hours advance notice'
                : 'Reservations require at least 30 minutes advance notice');
        }

        if (! $this->hours->acceptsReservationAt($reservationTime)) {
            throw new HttpException(422, sprintf(
                'Reservations are available from %s to %s.',
                config('restaurant.hours.opens_at'),
                config('restaurant.hours.last_reservation_at')
            ));
        }
    }

    /** Tối đa N lượt tương lai còn hiệu lực; không có hai lượt của cùng tài khoản chồng giờ. */
    private function assertAccountLimits(int $accountId, Carbon $start, int $guestCount, ?int $ignoreReservationId): void
    {
        $active = Reservation::query()
            ->where('ma_tai_khoan', $accountId)
            ->whereIn('trang_thai', [ReservationStatus::PENDING->value, ReservationStatus::CONFIRMED->value])
            ->when($ignoreReservationId !== null, fn ($query) => $query->where('ma_dat_ban', '!=', $ignoreReservationId))
            ->whereDoesntHave('serviceSessions', fn ($query) => $query->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
            ->get()
            ->filter(fn (Reservation $reservation): bool => $this->schedule->stillHolds($reservation));

        $limit = (int) config('restaurant.reservations.max_active_per_account', 3);
        if ($active->count() >= $limit) {
            throw new HttpException(409, "You already have {$limit} upcoming reservations. Cancel one before booking another.");
        }

        $end = $start->copy()->addMinutes($this->schedule->diningMinutes($guestCount));
        // Trùng giờ của chính khách: so khoảng dùng bàn thuần, không cộng thời gian dọn (ràng buộc của bàn).
        $own = $active->first(fn (Reservation $reservation): bool => $this->schedule->start($reservation)->lessThan($end)
            && $start->lessThan($this->schedule->end($reservation)));
        if ($own) {
            throw new HttpException(409, "You already have a reservation at {$own->thoi_gian->format('d/m H:i')} that overlaps this time.");
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

    private function isFree(int $tableId, Carbon $start, Carbon $end, ?int $ignoreReservationId = null): bool
    {
        return $this->schedule->conflictingReservations($tableId, $start, $end, $ignoreReservationId ? [$ignoreReservationId] : [])->isEmpty()
            && ! $this->schedule->conflictingSession($tableId, $start, $end);
    }

    private function assertNoOverlap(int $tableId, Carbon $reservationTime, int $guestCount, ?int $ignoredReservationId = null): void
    {
        $end = $reservationTime->copy()->addMinutes($this->schedule->diningMinutes($guestCount));

        if ($this->schedule->conflictingReservations($tableId, $reservationTime, $end, $ignoredReservationId ? [$ignoredReservationId] : [])->isNotEmpty()) {
            throw new HttpException(409, 'This table already has an overlapping reservation');
        }

        if ($this->schedule->conflictingSession($tableId, $reservationTime, $end)) {
            throw new HttpException(409, 'This table is still in use around that time. Choose a later time or another table.');
        }
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
}
