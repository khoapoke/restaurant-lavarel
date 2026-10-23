<?php

namespace App\Services\Shared;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ServiceSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lịch dùng bàn theo khoảng thời gian thật (ADR-025), thay cho hằng 119 phút:
 *  - Lượt đặt chiếm [giờ hẹn, giờ hẹn + thời lượng dùng bàn) cộng thời gian dọn bàn.
 *  - Phiên đang mở chiếm tới giờ kết thúc dự kiến; quá giờ thì coi như còn chiếm thêm N phút.
 *  - Lượt PENDING đã qua giờ hẹn và lượt CONFIRMED đã quá hạn giữ chỗ không còn chặn bàn,
 *    kể cả khi lệnh quét định kỳ chạy trễ.
 */
class ReservationSchedule
{
    public function diningMinutes(int $guestCount): int
    {
        return ServiceSession::expectedDiningMinutes($guestCount);
    }

    public function cleaningMinutes(): int
    {
        return (int) config('restaurant.reservations.cleaning_buffer_minutes', 15);
    }

    public function start(Reservation $reservation): Carbon
    {
        return $reservation->thoi_gian->copy();
    }

    public function end(Reservation $reservation): Carbon
    {
        return $this->start($reservation)->addMinutes($this->diningMinutes((int) $reservation->so_luong_khach));
    }

    /** Hạn giữ chỗ: giờ hẹn + thời gian ân hạn, hoặc mốc đã gia hạn. */
    public function holdUntil(Reservation $reservation): Carbon
    {
        $grace = $reservation->thoi_gian->copy()->addMinutes((int) config('restaurant.reservations.grace_minutes', 20));

        return $reservation->gia_han_den && $reservation->gia_han_den->greaterThan($grace)
            ? $reservation->gia_han_den->copy()
            : $grace;
    }

    /** Gia hạn tối đa: hạn giữ chỗ gốc + max_extension_minutes. */
    public function latestExtension(Reservation $reservation): Carbon
    {
        return $reservation->thoi_gian->copy()->addMinutes(
            (int) config('restaurant.reservations.grace_minutes', 20) + (int) config('restaurant.reservations.max_extension_minutes', 30)
        );
    }

    public function autoNoShowAt(Reservation $reservation): Carbon
    {
        return $this->holdUntil($reservation)->addMinutes((int) config('restaurant.reservations.auto_no_show_after_minutes', 60));
    }

    public function seatingOpensAt(Reservation $reservation): Carbon
    {
        return $reservation->thoi_gian->copy()->subMinutes((int) config('restaurant.reservations.early_seating_minutes', 30));
    }

    public function withinSeatingWindow(Reservation $reservation, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $at->betweenIncluded($this->seatingOpensAt($reservation), $this->holdUntil($reservation));
    }

    public function isSeated(Reservation $reservation): bool
    {
        return $reservation->serviceSessions()->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value)->exists();
    }

    /** Lượt còn giữ bàn ở thời điểm $at (đã xác nhận, chưa quá hạn giữ chỗ / PENDING chưa tới giờ). */
    public function stillHolds(Reservation $reservation, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return match ($reservation->trang_thai) {
            ReservationStatus::PENDING => $reservation->thoi_gian->greaterThan($at),
            ReservationStatus::CONFIRMED => $this->holdUntil($reservation)->greaterThanOrEqualTo($at),
            default => false,
        };
    }

    public function holdExpired(Reservation $reservation, ?CarbonInterface $at = null): bool
    {
        return $reservation->trang_thai === ReservationStatus::CONFIRMED
            && $this->holdUntil($reservation)->lessThan($at ?? now());
    }

    /**
     * Trạng thái hiển thị: phản ánh ngay hạn chờ/giữ chỗ dù lệnh quét định kỳ chưa chạy.
     */
    public function effectiveStatus(Reservation $reservation, bool $seated = false): ReservationStatus
    {
        if ($reservation->trang_thai === ReservationStatus::PENDING && $reservation->thoi_gian->lessThanOrEqualTo(now())) {
            return ReservationStatus::EXPIRED;
        }

        if ($reservation->trang_thai === ReservationStatus::CONFIRMED && ! $seated && $this->autoNoShowAt($reservation)->lessThanOrEqualTo(now())) {
            return ReservationStatus::NO_SHOW;
        }

        return $reservation->trang_thai;
    }

    /** Giờ kết thúc dự kiến của phiên; quá giờ thì coi như còn chiếm thêm overdue_session_block_minutes. */
    public function sessionBusyUntil(ServiceSession $session): Carbon
    {
        $expected = $session->du_kien_ket_thuc_luc?->copy()
            ?? ($session->mo_luc ?? now())->copy()->addMinutes($this->diningMinutes((int) $session->so_luong_khach));

        return $expected->lessThan(now())
            ? now()->addMinutes((int) config('restaurant.reservations.overdue_session_block_minutes', 60))
            : $expected;
    }

    /**
     * Các lượt đặt còn hiệu lực trên bàn có khoảng dùng bàn chồng lên [start, end) (đã cộng dọn bàn).
     *
     * @param  list<int>  $ignoreReservationIds
     * @return Collection<int, Reservation>
     */
    public function conflictingReservations(int $tableId, CarbonInterface $start, CarbonInterface $end, array $ignoreReservationIds = []): Collection
    {
        $clean = $this->cleaningMinutes();
        $windowStart = Carbon::instance($start)->subMinutes(
            (int) config('restaurant.reservations.dining_minutes_large_party', 120) + $clean
        );

        return Reservation::query()
            ->where('ma_ban', $tableId)
            ->whereIn('trang_thai', [ReservationStatus::PENDING->value, ReservationStatus::CONFIRMED->value])
            ->whereBetween('thoi_gian', [$windowStart, Carbon::instance($end)->addMinutes($clean)])
            ->when($ignoreReservationIds !== [], fn ($query) => $query->whereNotIn('ma_dat_ban', $ignoreReservationIds))
            ->whereDoesntHave('serviceSessions', fn ($query) => $query->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
            ->orderBy('thoi_gian')
            ->get()
            ->filter(fn (Reservation $reservation): bool => $this->stillHolds($reservation)
                && $this->overlaps($this->start($reservation), $this->end($reservation), $start, $end))
            ->values();
    }

    /**
     * Phiên đang mở trên bàn có thời gian chiếm chồng lên [start, end).
     */
    public function conflictingSession(int $tableId, CarbonInterface $start, CarbonInterface $end, ?int $ignoreSessionId = null): ?ServiceSession
    {
        return ServiceSession::query()
            ->whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $tableId))
            ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
            ->when($ignoreSessionId !== null, fn ($query) => $query->where('ma_phien', '!=', $ignoreSessionId))
            ->get()
            ->first(fn (ServiceSession $session): bool => $this->overlaps($session->mo_luc ?? now(), $this->sessionBusyUntil($session), $start, $end));
    }

    /** Lượt đặt còn hiệu lực kế tiếp trên bàn kể từ $from (chưa nhận khách). */
    public function nextReservation(int $tableId, CarbonInterface $from, array $ignoreReservationIds = []): ?Reservation
    {
        return Reservation::query()
            ->where('ma_ban', $tableId)
            ->whereIn('trang_thai', [ReservationStatus::PENDING->value, ReservationStatus::CONFIRMED->value])
            ->where('thoi_gian', '>=', Carbon::instance($from)->subMinutes(
                (int) config('restaurant.reservations.grace_minutes', 20) + (int) config('restaurant.reservations.max_extension_minutes', 30)
            ))
            ->when($ignoreReservationIds !== [], fn ($query) => $query->whereNotIn('ma_dat_ban', $ignoreReservationIds))
            ->whereDoesntHave('serviceSessions', fn ($query) => $query->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
            ->orderBy('thoi_gian')
            ->get()
            ->first(fn (Reservation $reservation): bool => $this->stillHolds($reservation, $from));
    }

    /** Hai khoảng [aStart, aEnd) và [bStart, bEnd) chồng nhau khi tính cả thời gian dọn bàn giữa hai lượt. */
    public function overlaps(CarbonInterface $aStart, CarbonInterface $aEnd, CarbonInterface $bStart, CarbonInterface $bEnd): bool
    {
        $clean = $this->cleaningMinutes();

        return $aStart->lessThan(Carbon::instance($bEnd)->addMinutes($clean))
            && $bStart->lessThan(Carbon::instance($aEnd)->addMinutes($clean));
    }
}
