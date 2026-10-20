<?php

namespace App\Enums;

enum OrderStatus: string
{
    case DRAFT = 'Nháp';
    case PENDING_CONFIRMATION = 'Chờ xác nhận';
    case CONFIRMED = 'Đã xác nhận';
    case PREPARING = 'Đang chuẩn bị';
    case READY = 'Sẵn sàng';
    case COMPLETED = 'Đã giao';
    case CANCELLED = 'Đã hủy';
}
