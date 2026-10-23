<?php

namespace App\Enums;

/** Thao tác STAFF đề nghị, ADMIN duyệt trên phiên đăng nhập của chính ADMIN. */
enum ApprovalType: string
{
    case ITEM_VOID = 'ITEM_VOID';
    case SEAT_OUTSIDE_WINDOW = 'SEAT_OUTSIDE_WINDOW';
    case SESSION_WRITE_OFF = 'SESSION_WRITE_OFF';

    public function label(): string
    {
        return match ($this) {
            self::ITEM_VOID => 'Hủy/miễn tiền món đã chế biến',
            self::SEAT_OUTSIDE_WINDOW => 'Nhận khách đặt trước ngoài khung giờ',
            self::SESSION_WRITE_OFF => 'Kết thúc phiên thất thu',
        };
    }
}
