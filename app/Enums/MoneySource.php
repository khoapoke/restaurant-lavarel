<?php

namespace App\Enums;

/** Nguồn ghi nhận một khoản tiền thực nhận (GIAO_DICH_TIEN.nguon). */
enum MoneySource: string
{
    case WEBHOOK = 'WEBHOOK';
    case MANUAL = 'MANUAL';
    case POS = 'POS';
    case COD = 'COD';
    case LEGACY = 'LEGACY';
}
