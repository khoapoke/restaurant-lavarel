<?php

namespace App\Modules\Core\Enums;

enum RoleType: string
{
    case ADMIN = 'Quản trị viên';
    case STAFF = 'Nhân viên';
    case CUSTOMER = 'Khách hàng';

    public function id(): int
    {
        return match ($this) {
            self::ADMIN => 1,
            self::STAFF => 2,
            self::CUSTOMER => 3,
        };
    }
}

