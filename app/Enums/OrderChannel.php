<?php

namespace App\Enums;

enum OrderChannel: string
{
    case ONLINE = 'ONLINE';
    case POS = 'POS';
    case TAKEAWAY = 'TAKEAWAY';
}
