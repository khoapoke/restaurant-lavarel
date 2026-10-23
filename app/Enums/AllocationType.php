<?php

namespace App\Enums;

enum AllocationType: string
{
    case APPLY = 'APPLY';
    case REFUND_REVERSAL = 'REFUND_REVERSAL';
}
