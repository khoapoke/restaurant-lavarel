<?php

namespace App\Enums;

/** Quyết định về tiền đã thu của đơn bị hủy. */
enum CancellationMoneyDecision: string
{
    case REFUND_DUE = 'REFUND_DUE';
    case RETAINED = 'RETAINED';
}
