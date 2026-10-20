<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING_CONFIRMATION = 'Chờ xác nhận';
    case CONFIRMED = 'Đã xác nhận';
    case PREPARING = 'Đang chuẩn bị';
    case READY = 'Sẵn sàng';
    case COMPLETED = 'Đã giao';
    case CANCELLED = 'Đã hủy';

    /**
     * Resolve OrderStatus enum case from name or value.
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

    /**
     * Determine if order status can transition to a target status according to state machine.
     */
    public function canTransitionTo(OrderStatus|string $newStatus): bool
    {
        $target = $newStatus instanceof self ? $newStatus : self::fromName($newStatus);
        if (!$target) {
            return false;
        }

        return match ($this) {
            self::PENDING_CONFIRMATION => in_array($target, [self::CONFIRMED, self::CANCELLED]),
            self::CONFIRMED => in_array($target, [self::PREPARING, self::CANCELLED]),
            self::PREPARING => in_array($target, [self::READY, self::CANCELLED]),
            self::READY => in_array($target, [self::COMPLETED, self::CANCELLED]),
            self::COMPLETED, self::CANCELLED => false,
        };
    }
}
