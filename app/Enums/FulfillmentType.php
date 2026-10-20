<?php

namespace App\Enums;

enum FulfillmentType: string
{
    case DELIVERY = 'DELIVERY';
    case PICKUP = 'PICKUP';
    case DINE_IN = 'DINE_IN';

    public function initialStatus(): FulfillmentStatus
    {
        return match ($this) {
            self::DINE_IN => FulfillmentStatus::SERVING,
            self::DELIVERY, self::PICKUP => FulfillmentStatus::PENDING,
        };
    }

    public function readyStatus(): FulfillmentStatus
    {
        return match ($this) {
            self::DELIVERY => FulfillmentStatus::AWAITING_DRIVER,
            self::PICKUP => FulfillmentStatus::AWAITING_CUSTOMER,
            self::DINE_IN => FulfillmentStatus::SERVING,
        };
    }
}
