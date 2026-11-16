<?php

namespace App\Modules\Core\Enums;

enum ChatSessionStatus: string
{
    case BOT = 'BOT';
    case WAITING_STAFF = 'WAITING_STAFF';
    case STAFF_CONNECTED = 'STAFF_CONNECTED';
    case CLOSED = 'CLOSED';

    /**
     * Resolve ChatSessionStatus enum case from name or value.
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

