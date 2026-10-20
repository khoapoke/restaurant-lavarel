<?php

namespace App\Enums;

enum KitchenItemStatus: string
{
    case DRAFT = 'DRAFT';
    case SENT = 'SENT';
    case QUEUED = 'QUEUED';
    case PREPARING = 'PREPARING';
    case READY = 'READY';
    case SERVED = 'SERVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
}
