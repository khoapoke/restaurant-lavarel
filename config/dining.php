<?php

/*
|--------------------------------------------------------------------------
| Mộc Live Dining (QR ordering at the table) — ADR-020
|--------------------------------------------------------------------------
*/

return [

    /*
     * Public base URL of the React app; table QR codes point to {frontend_url}/dine/{token}.
     */
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    /*
     * Kitchen SLA, measured from the moment an item is sent to the kitchen.
     */
    'kitchen' => [
        'warning_minutes' => (int) env('KITCHEN_SLA_WARNING_MINUTES', 10),
        'late_minutes' => (int) env('KITCHEN_SLA_LATE_MINUTES', 20),
    ],

    /*
     * A check-in request nobody handled within this window is treated as expired.
     */
    'check_in_expires_minutes' => (int) env('DINE_CHECK_IN_EXPIRES_MINUTES', 15),

    /*
     * How long a guest device may still read its bill after the session closed.
     */
    'visit_readable_hours' => (int) env('DINE_VISIT_READABLE_HOURS', 6),

    /*
     * Per-session table code (ADR-022). A new device must enter it to join an open
     * session; after this many wrong codes the session locks until staff issue a new one.
     */
    'join_code' => [
        'max_failures' => (int) env('DINE_JOIN_CODE_MAX_FAILURES', 5),
    ],

    'ordering' => [
        'max_lines_per_round' => 20,
        'max_quantity_per_line' => 20,
    ],

    'service_requests' => [
        /* Seconds before the same request type can be raised again after it was closed. */
        'cooldown_seconds' => (int) env('DINE_SERVICE_REQUEST_COOLDOWN', 30),
        /* Maximum simultaneously open "OTHER" requests per session. */
        'max_open_other' => 3,
    ],

    /*
     * Demo mode (`php artisan dining:demo`). Never runs in production.
     */
    'demo' => [
        'enabled' => (bool) env('LIVE_DINING_DEMO_ENABLED', true),
        'table_name' => env('LIVE_DINING_DEMO_TABLE', 'Bàn L01'),
        'table_location' => 'Tầng 1 - Quầy Live Dining (bàn trình diễn QR)',
        'table_capacity' => 4,
    ],

];
