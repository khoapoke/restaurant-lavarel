<?php

namespace App\Enums;

/** Trạng thái đối soát; số tiền đã phân bổ/đã hoàn được lưu riêng, không dồn vào trạng thái. */
enum ReconciliationStatus: string
{
    case MATCHED = 'MATCHED';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case RESOLVED = 'RESOLVED';
}
