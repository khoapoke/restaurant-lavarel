<?php

namespace App\Services\Shared;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Giờ hoạt động (config/restaurant.php → hours). Mọi mốc so theo app.timezone.
 * Thời điểm GỬI yêu cầu khác thời điểm KHÁCH ĐẾN/NHẬN: chỉ thời điểm phục vụ bị giới hạn.
 */
class OpeningHours
{
    public function at(CarbonInterface $day, string $key): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config("restaurant.hours.{$key}")));

        return Carbon::instance($day)->setTimezone(config('app.timezone'))->setTime($hour, $minute);
    }

    /** Có đang nhận gọi món mới (POS, QR, đặt online giao/lấy ngay) không. */
    public function acceptsOrdersAt(CarbonInterface $moment): bool
    {
        return $moment->betweenIncluded($this->at($moment, 'opens_at'), $this->at($moment, 'last_order_at'));
    }

    public function assertAcceptsOrders(?CarbonInterface $moment = null): void
    {
        $moment ??= now();
        if (! $this->acceptsOrdersAt($moment)) {
            throw new HttpException(409, sprintf(
                'The kitchen takes new orders from %s to %s.',
                config('restaurant.hours.opens_at'),
                config('restaurant.hours.last_order_at')
            ));
        }
    }

    /** Giờ khách đến của một lượt đặt bàn: [opens_at, last_reservation_at]. */
    public function acceptsReservationAt(CarbonInterface $arrival): bool
    {
        return $arrival->betweenIncluded($this->at($arrival, 'opens_at'), $this->at($arrival, 'last_reservation_at'));
    }

    /**
     * @return array{opensAt: string, closesAt: string, lastReservationAt: string, lastOrderAt: string, timezone: string, orderingOpen: bool}
     */
    public function summary(): array
    {
        return [
            'opensAt' => (string) config('restaurant.hours.opens_at'),
            'closesAt' => (string) config('restaurant.hours.closes_at'),
            'lastReservationAt' => (string) config('restaurant.hours.last_reservation_at'),
            'lastOrderAt' => (string) config('restaurant.hours.last_order_at'),
            'timezone' => (string) config('app.timezone'),
            'orderingOpen' => $this->acceptsOrdersAt(now()),
        ];
    }
}
