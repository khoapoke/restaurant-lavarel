<?php

namespace App\Enums;

enum TableStatus: string
{
    case AVAILABLE = 'Trống';
    case RESERVED = 'Đã đặt';
    case OCCUPIED = 'Đang phục vụ';
    case CLEANING = 'Chờ vệ sinh';
    case MAINTENANCE = 'Bảo trì';
}
