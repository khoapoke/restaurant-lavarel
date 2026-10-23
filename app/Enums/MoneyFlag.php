<?php

namespace App\Enums;

/** Lý do một khoản tiền cần đối soát. */
enum MoneyFlag: string
{
    case OVERPAID = 'OVERPAID';
    case UNDERPAID = 'UNDERPAID';
    case DUPLICATE = 'DUPLICATE';
    case ORDER_CANCELLED = 'ORDER_CANCELLED';
    case INVOICE_STALE = 'INVOICE_STALE';
    case SESSION_CLOSED = 'SESSION_CLOSED';
    case UNKNOWN_TARGET = 'UNKNOWN_TARGET';
    case NO_REFERENCE = 'NO_REFERENCE';

    public function label(): string
    {
        return match ($this) {
            self::OVERPAID => 'Chuyển dư',
            self::UNDERPAID => 'Chuyển thiếu',
            self::DUPLICATE => 'Chuyển thêm cho khoản đã đủ',
            self::ORDER_CANCELLED => 'Tiền về cho đơn đã hủy',
            self::INVOICE_STALE => 'QR hóa đơn cũ hoặc đã hủy',
            self::SESSION_CLOSED => 'Tiền về sau khi phiên đã kết thúc',
            self::UNKNOWN_TARGET => 'Không xác định được đơn/hóa đơn',
            self::NO_REFERENCE => 'Thiếu mã giao dịch ngân hàng',
        };
    }
}
