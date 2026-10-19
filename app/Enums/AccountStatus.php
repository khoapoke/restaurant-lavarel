<?php

namespace App\Enums;

enum AccountStatus: string
{
    case ACTIVE = 'Hoạt động';
    case LOCKED = 'Bị khóa';
}
