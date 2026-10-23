<?php

namespace App\Enums;

/** Lý do hủy/từ chối một dòng món. Lỗi phía nhà hàng không thu tiền; khách đổi ý sau khi bếp bắt đầu vẫn tính tiền trừ khi ADMIN miễn. */
enum ItemVoidReason: string
{
    case CUSTOMER_CHANGED_MIND = 'CUSTOMER_CHANGED_MIND';
    case ORDER_ENTRY_ERROR = 'ORDER_ENTRY_ERROR';
    case OUT_OF_STOCK = 'OUT_OF_STOCK';
    case RESTAURANT_MISTAKE = 'RESTAURANT_MISTAKE';
    case DAMAGED = 'DAMAGED';
    case NOT_SERVED = 'NOT_SERVED';

    public function isRestaurantFault(): bool
    {
        return $this !== self::CUSTOMER_CHANGED_MIND;
    }

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_CHANGED_MIND => 'Khách đổi ý',
            self::ORDER_ENTRY_ERROR => 'Nhân viên gọi nhầm',
            self::OUT_OF_STOCK => 'Hết nguyên liệu',
            self::RESTAURANT_MISTAKE => 'Bếp làm sai',
            self::DAMAGED => 'Món hỏng, đổ vỡ',
            self::NOT_SERVED => 'Khách chưa nhận được món',
        };
    }
}
