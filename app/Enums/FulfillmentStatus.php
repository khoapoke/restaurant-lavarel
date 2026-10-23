<?php

namespace App\Enums;

enum FulfillmentStatus: string
{
    case PENDING = 'PENDING';
    case AWAITING_DRIVER = 'AWAITING_DRIVER';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERED = 'DELIVERED';
    case AWAITING_CUSTOMER = 'AWAITING_CUSTOMER';
    case COLLECTED = 'COLLECTED';
    case SERVING = 'SERVING';
    case SERVED = 'SERVED';
    case DELIVERY_FAILED = 'DELIVERY_FAILED';

    public function canTransitionTo(FulfillmentType $type, self $target): bool
    {
        return match ($type) {
            FulfillmentType::DELIVERY => match ($this) {
                self::AWAITING_DRIVER => $target === self::OUT_FOR_DELIVERY,
                self::OUT_FOR_DELIVERY => in_array($target, [self::DELIVERED, self::DELIVERY_FAILED], true),
                self::DELIVERY_FAILED => $target === self::AWAITING_DRIVER,
                default => false,
            },
            FulfillmentType::PICKUP => $this === self::AWAITING_CUSTOMER
                && $target === self::COLLECTED,
            FulfillmentType::DINE_IN => $this === self::SERVING
                && $target === self::SERVED,
        };
    }

    public function completesOrder(): bool
    {
        return in_array($this, [self::DELIVERED, self::COLLECTED, self::SERVED], true);
    }
}
