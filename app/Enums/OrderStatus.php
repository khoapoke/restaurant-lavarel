<?php

namespace App\Enums;

enum OrderStatus: string
{
    case DRAFT = 'Nháp';
    case PENDING_CONFIRMATION = 'Chờ xác nhận';
    case CONFIRMED = 'Đã xác nhận';
    case PREPARING = 'Đang chuẩn bị';
    case READY = 'Sẵn sàng';
    case COMPLETED = 'Đã giao';
    case CANCELLED = 'Đã hủy';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING_CONFIRMATION => $target === self::CONFIRMED,
            self::CONFIRMED => $target === self::PREPARING,
            self::PREPARING => $target === self::READY,
            default => false,
        };
    }

    public function canBeCancelledByAdmin(): bool
    {
        return in_array($this, [
            self::PENDING_CONFIRMATION,
            self::CONFIRMED,
            self::PREPARING,
            self::READY,
        ], true);
    }
}
