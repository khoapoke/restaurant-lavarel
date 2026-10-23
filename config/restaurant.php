<?php

/*
|--------------------------------------------------------------------------
| Chính sách vận hành Mộc Dining (ADR-023 → ADR-027)
|--------------------------------------------------------------------------
| Các giá trị mặc định do người dùng chốt cho milestone "ngoại lệ nghiệp vụ".
| Mọi mốc giờ dùng app.timezone (Asia/Ho_Chi_Minh). Thay đổi qua .env khi cần.
*/

return [

    /*
     * Giờ hoạt động. Khách được GỬI yêu cầu đặt bàn bất kỳ lúc nào; giờ khách ĐẾN phải
     * nằm trong [opens_at, last_reservation_at]. Gọi món mới (POS, QR, online ASAP) chỉ
     * trong [opens_at, last_order_at]. Phiên đã mở vẫn được thanh toán/đóng/xử lý sau giờ.
     */
    'hours' => [
        'opens_at' => env('RESTAURANT_OPENS_AT', '10:00'),
        'closes_at' => env('RESTAURANT_CLOSES_AT', '22:30'),
        'last_reservation_at' => env('RESTAURANT_LAST_RESERVATION_AT', '21:30'),
        'last_order_at' => env('RESTAURANT_LAST_ORDER_AT', '22:00'),
    ],

    'reservations' => [
        /* Thời lượng dùng bàn dự kiến theo số khách. */
        'dining_minutes_small_party' => (int) env('RESERVATION_DINING_MINUTES_SMALL', 90),
        'dining_minutes_large_party' => (int) env('RESERVATION_DINING_MINUTES_LARGE', 120),
        'small_party_max_guests' => 4,
        /* Thời gian vệ sinh giữa hai lượt. */
        'cleaning_buffer_minutes' => (int) env('RESERVATION_CLEANING_MINUTES', 15),
        /* Cho nhận khách sớm tối đa N phút trước giờ hẹn. */
        'early_seating_minutes' => (int) env('RESERVATION_EARLY_SEATING_MINUTES', 30),
        /* Giữ chỗ khi khách trễ. */
        'grace_minutes' => (int) env('RESERVATION_GRACE_MINUTES', 20),
        /* Gia hạn tối đa khi khách báo trễ (cộng dồn sau hạn giữ chỗ). */
        'max_extension_minutes' => (int) env('RESERVATION_MAX_EXTENSION_MINUTES', 30),
        /* Sau N phút kể từ hạn giữ chỗ cuối cùng, lượt chưa nhận bàn tự thành NO_SHOW. */
        'auto_no_show_after_minutes' => (int) env('RESERVATION_AUTO_NO_SHOW_MINUTES', 60),
        /* Số lượt đặt tương lai đang hoạt động tối đa mỗi tài khoản. */
        'max_active_per_account' => (int) env('RESERVATION_MAX_ACTIVE_PER_ACCOUNT', 3),
        /* Phiên đã quá giờ dự kiến: coi như chưa trả bàn trong N phút tới khi tính bàn trống. */
        'overdue_session_block_minutes' => (int) env('RESERVATION_OVERDUE_SESSION_BLOCK_MINUTES', 60),
        /* Khách vãng lai phải còn ít nhất N phút trước lượt đặt kế tiếp. */
        'min_walk_in_minutes' => (int) env('RESERVATION_MIN_WALK_IN_MINUTES', 30),
        /* Báo trước tối thiểu (khách) và với yêu cầu đặc biệt. */
        'advance_notice_minutes' => 30,
        'special_request_notice_minutes' => 120,
        /* Khách tự sửa/hủy trước giờ hẹn tối thiểu N phút. */
        'customer_modify_cutoff_minutes' => 60,
    ],

    'payments' => [
        /* Dung sai chênh lệch chuyển khoản (đồng). */
        'tolerance' => (float) env('PAYMENT_TOLERANCE', 0),
        /* Mã giao dịch ngân hàng nhập tay tối thiểu. */
        'min_reference_length' => 6,
        /* Nhà cung cấp mặc định cho khóa duy nhất mã giao dịch khi payload không ghi rõ. */
        'default_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'VIETQR'),
        /*
         * Định danh một khoản chuyển khoản = ngân hàng nhận + số tài khoản nhận + mã giao dịch ngân hàng.
         * Nhãn nguồn (VietQR, nhập tay, POS) không phải ngân hàng nên không tham gia định danh: khoản vào
         * tài khoản nhận đã cấu hình (services.vietqr) luôn thuộc ngân hàng của tài khoản đó.
         */
        'generic_providers' => ['VIETQR', 'NAPAS', 'NAPAS247', 'QR', 'MANUAL', 'POS', 'BANKTRANSFER', 'WEBHOOK', 'LEGACY'],
        'bank_aliases' => [
            'VCB' => ['VCB', 'VIETCOMBANK', '970436'],
            'MB' => ['MB', 'MBBANK', 'MBB', '970422'],
            'TCB' => ['TCB', 'TECHCOMBANK', '970407'],
            'ACB' => ['ACB', '970416'],
            'BIDV' => ['BIDV', '970418'],
            'VTB' => ['VTB', 'ICB', 'VIETINBANK', '970415'],
            'TPB' => ['TPB', 'TPBANK', '970423'],
            'VPB' => ['VPB', 'VPBANK', '970432'],
            'STB' => ['STB', 'SACOMBANK', '970403'],
            'VBA' => ['VBA', 'AGRIBANK', '970405'],
        ],
    ],

    'kitchen' => [
        /* Hoàn tác bước bếp bấm nhầm trong N giây. */
        'undo_window_seconds' => 120,
    ],

    'fulfillment' => [
        /* Đơn hẹn giờ xuất hiện trên KDS từ scheduledAt − N phút. */
        'scheduled_prep_lead_minutes' => (int) env('FULFILLMENT_SCHEDULED_LEAD_MINUTES', 30),
        /* Hẹn lấy tối đa N ngày tới. */
        'max_schedule_days' => 7,
        /* Cảnh báo khách tự lấy quá hạn. */
        'pickup_overdue_minutes' => 60,
        /* Cảnh báo món đã xong chờ tài xế quá lâu. */
        'delivery_handoff_warning_minutes' => 20,
    ],

];
