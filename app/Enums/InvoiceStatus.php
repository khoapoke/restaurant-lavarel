<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case OPEN = 'OPEN';
    case PAID = 'PAID';
    case VOID = 'VOID';
}
