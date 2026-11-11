<?php

namespace App\Modules\Core\Enums;

enum ReservationStatus: string
{
    case PENDING = 'Chờ xử lý';
    case CONFIRMED = 'Đã xác nhận';
    case REJECTED = 'Từ chối';
    case CANCELLED = 'Đã hủy';
    case COMPLETED = 'Hoàn thành';

    /**
     * Resolve ReservationStatus enum case from name or value.
     */
    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name || $case->value === $name) {
                return $case;
            }
        }
        return null;
    }
}

