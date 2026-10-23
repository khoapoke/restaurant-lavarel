<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID = 'Chưa thanh toán';
    case PAID = 'Đã thanh toán';
    case FAILED = 'Thất bại';
    case REFUNDED = 'Đã hoàn tiền';
    case PARTIALLY_PAID = 'Thanh toán một phần';
    case PARTIALLY_REFUNDED = 'Hoàn một phần';
}
