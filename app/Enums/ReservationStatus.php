<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case PENDING = 'Chờ xử lý';
    case CONFIRMED = 'Đã xác nhận';
    case REJECTED = 'Từ chối';
    case CANCELLED = 'Đã hủy';
    case COMPLETED = 'Hoàn thành';
    case EXPIRED = 'Hết hạn';
    case NO_SHOW = 'Không đến';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING => in_array($target, [self::CONFIRMED, self::REJECTED, self::CANCELLED, self::EXPIRED], true),
            self::CONFIRMED => in_array($target, [self::COMPLETED, self::CANCELLED, self::NO_SHOW], true),
            default => false,
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::PENDING, self::CONFIRMED], true);
    }
}
