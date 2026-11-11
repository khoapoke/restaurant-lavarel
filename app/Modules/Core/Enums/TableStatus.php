<?php

namespace App\Modules\Core\Enums;

enum TableStatus: string
{
    case AVAILABLE = 'Trống';
    case RESERVED = 'Đã đặt';
    case OCCUPIED = 'Đang phục vụ';
    case MAINTENANCE = 'Bảo trì';

    /**
     * Resolve TableStatus enum case from name or value.
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

