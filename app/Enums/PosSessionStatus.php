<?php

namespace App\Enums;

enum PosSessionStatus: string
{
    case OPEN = 'OPEN';
    case AWAITING_PAYMENT = 'AWAITING_PAYMENT';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';
}
