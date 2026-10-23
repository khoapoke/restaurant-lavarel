<?php

namespace App\Services\Staff;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\ReservationSchedule;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Xử lý khách đặt bàn đến trễ (ADR-025): gia hạn có lý do, đánh dấu không đến, quét tự động.
 * STAFF được làm các thao tác này; mọi thao tác ghi người, thời điểm, lý do.
 */
class ReservationDeskService
{
    public function __construct(
        private readonly ReservationSchedule $schedule,
        private readonly TableStatusSynchronizer $tables,
        private readonly AuditLogger $audit,
    ) {}

    /** Gia hạn giữ chỗ thêm $minutes phút (cộng dồn tối đa max_extension_minutes sau hạn gốc). */
    public function extend(Reservation $reservation, Account $actor, int $minutes, string $reason): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor, $minutes, $reason): Reservation {
            $locked = $this->lockUnseatedConfirmed($reservation);
            $currentHold = $this->schedule->holdUntil($locked);
            $latest = $this->schedule->latestExtension($locked);

            if ($minutes < 1) {
                throw new HttpException(422, 'Choose how many minutes to extend.');
            }
            if ($this->schedule->autoNoShowAt($locked)->isPast()) {
                throw new HttpException(409, 'This reservation is already past the no-show deadline.');
            }

            $newHold = max($currentHold, now())->copy()->addMinutes($minutes);
            if ($newHold->greaterThan($latest)) {
                throw new HttpException(409, sprintf('The hold can be extended until %s at the latest.', $latest->format('H:i')));
            }

            // Khách đến muộn nhất lúc $newHold vẫn phải dùng bàn được mà không chạm lượt kế tiếp.
            $end = $newHold->copy()->addMinutes($this->schedule->diningMinutes((int) $locked->so_luong_khach));
            $conflict = $this->schedule->conflictingReservations((int) $locked->ma_ban, $newHold, $end, [(int) $locked->ma_dat_ban])->first();
            if ($conflict) {
                throw new HttpException(409, sprintf('Extending would overlap reservation #%d at %s.', $conflict->ma_dat_ban, $conflict->thoi_gian->format('H:i')));
            }
            // Bàn đã được giao cho khách khác (phiên đang mở chiếm bàn tới sau giờ khách đặt đến): không gia hạn.
            $occupying = $this->schedule->conflictingSession((int) $locked->ma_ban, $newHold, $end);
            if ($occupying) {
                throw new HttpException(409, sprintf('The table is in use by session #%d until about %s. Seat this booking at another table instead.', $occupying->ma_phien, $this->schedule->sessionBusyUntil($occupying)->format('H:i')));
            }

            $before = ['holdUntil' => $currentHold->toIso8601String()];
            $locked->update([
                'gia_han_den' => $newHold,
                'ly_do_gia_han' => $reason,
                'ma_nguoi_gia_han' => $actor->ma_tai_khoan,
            ]);
            $this->audit->record($actor, 'RESERVATION_EXTENDED', 'RESERVATION', (int) $locked->ma_dat_ban, $reason, $before, ['holdUntil' => $newHold->toIso8601String()]);
            $this->tables->refreshReservationHold(DiningTable::query()->lockForUpdate()->findOrFail($locked->ma_ban));

            return $locked->fresh(['account.userProfile', 'diningTable']);
        });
    }

    /** Đánh dấu không đến sau khi hết hạn giữ chỗ; bàn được trả lại ngay. */
    public function markNoShow(Reservation $reservation, ?Account $actor, string $reason): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $locked = $this->lockUnseatedConfirmed($reservation);

            if (! $this->schedule->holdExpired($locked)) {
                throw new HttpException(409, sprintf('The table is held until %s. Extend or wait before marking a no-show.', $this->schedule->holdUntil($locked)->format('H:i')));
            }

            $this->close($locked, ReservationStatus::NO_SHOW, $actor, $reason);

            return $locked->fresh(['account.userProfile', 'diningTable']);
        });
    }

    /**
     * Quét định kỳ: PENDING quá giờ hẹn → EXPIRED; CONFIRMED chưa nhận bàn quá hạn tự động → NO_SHOW.
     *
     * @return array{expired: int, noShow: int}
     */
    public function sweep(): array
    {
        $expired = 0;
        $noShow = 0;

        Reservation::query()
            ->where('trang_thai', ReservationStatus::PENDING->value)
            ->where('thoi_gian', '<=', now())
            ->pluck('ma_dat_ban')
            ->each(function (int $id) use (&$expired): void {
                DB::transaction(function () use ($id, &$expired): void {
                    $reservation = Reservation::query()->lockForUpdate()->find($id);
                    if ($reservation?->trang_thai === ReservationStatus::PENDING && $reservation->thoi_gian->lessThanOrEqualTo(now())) {
                        $this->close($reservation, ReservationStatus::EXPIRED, null, 'Tự động: hết hạn chờ xác nhận');
                        $expired++;
                    }
                });
            });

        Reservation::query()
            ->where('trang_thai', ReservationStatus::CONFIRMED->value)
            ->where('thoi_gian', '<=', now())
            ->whereDoesntHave('serviceSessions', fn ($query) => $query->where('trang_thai', '!=', PosSessionStatus::CANCELLED->value))
            ->get()
            ->filter(fn (Reservation $reservation): bool => $this->schedule->autoNoShowAt($reservation)->lessThanOrEqualTo(now()))
            ->each(function (Reservation $candidate) use (&$noShow): void {
                DB::transaction(function () use ($candidate, &$noShow): void {
                    $reservation = Reservation::query()->lockForUpdate()->find($candidate->ma_dat_ban);
                    if ($reservation?->trang_thai === ReservationStatus::CONFIRMED && ! $this->schedule->isSeated($reservation)) {
                        $this->close($reservation, ReservationStatus::NO_SHOW, null, 'Tự động: quá hạn giữ chỗ');
                        $noShow++;
                    }
                });
            });

        DiningTable::query()->get()->each(fn (DiningTable $table) => $this->tables->refreshReservationHold($table));

        return ['expired' => $expired, 'noShow' => $noShow];
    }

    /** Ghi nhận kết thúc lượt đặt (không đến / hết hạn / hủy / từ chối) với người, lý do, thời điểm. */
    public function close(Reservation $reservation, ReservationStatus $target, ?Account $actor, ?string $reason): void
    {
        $before = ['status' => $reservation->trang_thai->name];
        $reservation->update([
            'trang_thai' => $target,
            'xu_ly_luc' => now(),
            'ma_nguoi_xu_ly' => $actor?->ma_tai_khoan,
            'ly_do_xu_ly' => $reason,
        ]);
        $this->audit->record($actor, 'RESERVATION_'.$target->name, 'RESERVATION', (int) $reservation->ma_dat_ban, $reason, $before, ['status' => $target->name]);

        $table = DiningTable::query()->find($reservation->ma_ban);
        if ($table) {
            $this->tables->refreshReservationHold($table);
        }
    }

    private function lockUnseatedConfirmed(Reservation $reservation): Reservation
    {
        $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->ma_dat_ban);
        if ($locked->trang_thai !== ReservationStatus::CONFIRMED) {
            throw new HttpException(409, 'Only a confirmed reservation can be handled here.');
        }
        if ($this->schedule->isSeated($locked)) {
            throw new HttpException(409, 'The guests are already seated.');
        }

        return $locked;
    }
}
