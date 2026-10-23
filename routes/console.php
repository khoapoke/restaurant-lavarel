<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-025: hết hạn lượt chờ xác nhận, tự đánh dấu không đến, làm mới trạng thái giữ bàn.
// API vẫn tự kiểm tra hạn nên nếu lệnh chạy trễ, bàn quá hạn giữ chỗ cũng không bị chặn.
Schedule::command('dining:reservations:sweep')->everyFiveMinutes()->withoutOverlapping();
