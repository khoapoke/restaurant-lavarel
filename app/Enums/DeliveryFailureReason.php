<?php

namespace App\Enums;

enum DeliveryFailureReason: string
{
    case CUSTOMER_UNREACHABLE = 'CUSTOMER_UNREACHABLE';
    case CUSTOMER_REFUSED = 'CUSTOMER_REFUSED';
    case DRIVER_CANCELLED = 'DRIVER_CANCELLED';
    case ADDRESS_PROBLEM = 'ADDRESS_PROBLEM';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_UNREACHABLE => 'Không liên lạc được khách',
            self::CUSTOMER_REFUSED => 'Khách từ chối nhận',
            self::DRIVER_CANCELLED => 'Tài xế hủy chuyến',
            self::ADDRESS_PROBLEM => 'Địa chỉ không giao được',
            self::OTHER => 'Lý do khác',
        };
    }
}
