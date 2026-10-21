<?php

namespace App\Enums;

/**
 * Who placed an ordering round (PHIEU_BEP): floor staff at the POS or a guest
 * scanning the table QR code.
 */
enum KitchenTicketSource: string
{
    case POS = 'POS';
    case TABLE_QR = 'TABLE_QR';
}
