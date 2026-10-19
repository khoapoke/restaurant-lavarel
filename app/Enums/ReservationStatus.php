<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case PENDING = 'Chờ xử lý';
    case CONFIRMED = 'Đã xác nhận';
    case REJECTED = 'Từ chối';
    case CANCELLED = 'Đã hủy';
    case COMPLETED = 'Hoàn thành';
}
