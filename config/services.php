<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'esms' => [
        'api_key' => env('ESMS_API_KEY'),
        'secret_key' => env('ESMS_SECRET_KEY'),
        'sandbox' => (bool) env('ESMS_SANDBOX', true),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'vietqr' => [
        'bank_bin' => env('VIETQR_BANK_BIN', '970436'),
        'account_no' => env('VIETQR_ACCOUNT_NO', '1029384756'),
        'account_name' => env('VIETQR_ACCOUNT_NAME', 'MOC DINING'),
        'template' => env('VIETQR_TEMPLATE', 'compact2'),
        'expires_minutes' => (int) env('VIETQR_EXPIRES_MINUTES', 15),
    ],

    'payment_webhook' => [
        'secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'allow_unsigned' => (bool) env('PAYMENT_WEBHOOK_ALLOW_UNSIGNED', false),
    ],

];
