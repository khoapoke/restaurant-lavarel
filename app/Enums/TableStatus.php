<?php

namespace App\Enums;

enum TableStatus: string
{
    case AVAILABLE = 'Trống';
    case RESERVED = 'Đã đặt';
    case OCCUPIED = 'Đang phục vụ';
    case MAINTENANCE = 'Bảo trì';
}
