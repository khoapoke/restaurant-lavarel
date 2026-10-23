<?php

namespace App\Enums;

enum SessionEndType: string
{
    case NORMAL = 'NORMAL';
    case WRITE_OFF = 'WRITE_OFF';
    /** Mọi món đã hủy/bị bếp từ chối, không còn phần tính tiền: kết thúc không phát sinh tiền. */
    case NO_CHARGE = 'NO_CHARGE';
}
