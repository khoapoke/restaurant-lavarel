<?php

namespace App\Modules\Core\Enums;

enum ChatSenderType: string
{
    case CUSTOMER = 'CUSTOMER';
    case BOT = 'BOT';
    case STAFF = 'STAFF';

    /**
     * Resolve ChatSenderType enum case from name or value.
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

