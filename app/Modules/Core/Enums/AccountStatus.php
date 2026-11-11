<?php

namespace App\Modules\Core\Enums;

enum AccountStatus: string
{
    case ACTIVE = 'Hoạt động';
    case LOCKED = 'Bị khóa';
}

