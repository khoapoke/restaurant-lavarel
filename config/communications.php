<?php

return [
    'enabled' => (bool) env('COMMUNICATIONS_ENABLED', true),
    'email_enabled' => (bool) env('COMMUNICATIONS_EMAIL_ENABLED', false),
    'test_recipient' => env('COMMUNICATIONS_TEST_RECIPIENT'),
    'queue' => 'communications',
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'),
    'reminder_minutes' => (int) env('RESERVATION_REMINDER_MINUTES', 60),
    'feedback_delay_minutes' => 120,
    // Hạn gửi email tự động, tính từ lúc tạo thông báo (retry không gia hạn). Nhắc lịch hết hạn tại giờ hẹn.
    'email_ttl_minutes' => [
        'update' => (int) env('EMAIL_TTL_UPDATE_MINUTES', 120),
        'closure' => (int) env('EMAIL_TTL_CLOSURE_MINUTES', 1440),
        'support_reply' => (int) env('EMAIL_TTL_SUPPORT_REPLY_MINUTES', 1440),
        'feedback' => (int) env('EMAIL_TTL_FEEDBACK_MINUTES', 2880),
        'receipt' => (int) env('EMAIL_TTL_RECEIPT_MINUTES', 10080),
        'default' => (int) env('EMAIL_TTL_DEFAULT_MINUTES', 1440),
    ],
    // Scheduler không đưa lại một việc đã vào hàng đợi trong thời gian lease, và ngừng đẩy thêm khi
    // hàng đợi có việc tồn quá `stalled_queue_minutes` (worker dừng) để bảng jobs không phình.
    'dispatch_lease_minutes' => (int) env('COMMUNICATIONS_DISPATCH_LEASE_MINUTES', 10),
    'stalled_queue_minutes' => (int) env('COMMUNICATIONS_STALLED_QUEUE_MINUTES', 2),
    'rate_limits' => [
        'customer_per_minute' => (int) env('SUPPORT_CUSTOMER_PER_MINUTE', 12),
        'staff_per_minute' => (int) env('SUPPORT_STAFF_PER_MINUTE', 60),
        'email_retry_per_minute' => (int) env('EMAIL_RETRY_PER_MINUTE', 10),
    ],
    'ai' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'enabled' => (bool) env('GEMINI_ENABLED', true),
        'daily_account_limit' => (int) env('AI_DAILY_ACCOUNT_LIMIT', 40),
        'daily_global_limit' => (int) env('AI_DAILY_GLOBAL_LIMIT', 500),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 700),
        'timeout' => 20,
        // Câu hỏi chờ quá lâu (worker dừng) được chuyển nhân viên thay vì để AI trả lời muộn.
        'stale_after_minutes' => (int) env('AI_STALE_AFTER_MINUTES', 10),
    ],
];
