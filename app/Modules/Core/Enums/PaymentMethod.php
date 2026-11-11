<?php

namespace App\Modules\Core\Enums;

enum PaymentMethod: string
{
    case CASH = 'Tiền mặt';
    case BANK_TRANSFER_QR = 'Chuyển khoản';
}

