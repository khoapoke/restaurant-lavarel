<?php

namespace App\Enums;

enum ServiceRequestType: string
{
    case CALL_STAFF = 'CALL_STAFF';
    case WATER = 'WATER';
    case CUTLERY = 'CUTLERY';
    case CLEAN_TABLE = 'CLEAN_TABLE';
    case PAYMENT = 'PAYMENT';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CALL_STAFF => 'Gọi nhân viên',
            self::WATER => 'Xin thêm nước',
            self::CUTLERY => 'Xin thêm dụng cụ',
            self::CLEAN_TABLE => 'Dọn bàn',
            self::PAYMENT => 'Yêu cầu thanh toán',
            self::OTHER => 'Yêu cầu khác',
        };
    }

    /** Payment requests jump to the top of the POS queue. */
    public function isPriority(): bool
    {
        return $this === self::PAYMENT;
    }
}
