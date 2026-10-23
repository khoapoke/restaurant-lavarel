<?php

namespace App\Enums;

/** Loại dòng món: thường, làm lại (không thu tiền), đổi món (thay cho dòng gốc). */
enum OrderLineType: string
{
    case NORMAL = 'NORMAL';
    case REMAKE = 'REMAKE';
    case EXCHANGE = 'EXCHANGE';
}
