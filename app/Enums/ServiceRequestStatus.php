<?php

namespace App\Enums;

enum ServiceRequestStatus: string
{
    case NEW = 'NEW';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return in_array($this, [self::NEW, self::ACKNOWLEDGED], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::NEW->value, self::ACKNOWLEDGED->value];
    }
}
