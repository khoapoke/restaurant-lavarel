<?php

namespace App\Modules\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MakeModuleCommand extends Command
{
    /**
     * Cú pháp lệnh: 
     * php artisan module:make {module} {type} {name}
     * Ví dụ: php artisan module:make Menu controller Customer/FeedbackController
     */
    protected $signature = 'module:make 
        {module : Tên Module (ví dụ: Menu, Order, Auth, Cart)} 
        {type : Loại file (controller, request, service, resource, model)} 
        {name : Tên class hoặc đường dẫn con (ví dụ: Customer/FeedbackController)}';

    /**
     * Mô tả hiển thị khi gõ "php artisan list"
     */
    protected $description = 'Tạo nhanh Controller, Request, Service, Resource hoặc Model bên trong Module';

    public function handle(): int
    {
        // 1. Chuẩn hóa tham số: Tên module viết hoa chữ cái đầu (Menu, Order...)
        $module = ucfirst($this->argument('module'));
        $type = strtolower($this->argument('type'));
        $name = $this->argument('name');

        // 2. Tự động ráp đường dẫn và lệnh gốc tương ứng của Laravel
        $mapping = match ($type) {
            'controller' => [
                'command' => 'make:controller',
                'path' => "App/Modules/{$module}/Http/Controllers/{$name}",
            ],
            'request' => [
                'command' => 'make:request',
                'path' => "App/Modules/{$module}/Http/Requests/{$name}",
            ],
            'resource' => [
                'command' => 'make:resource',
                'path' => "App/Modules/{$module}/Http/Resources/{$name}",
            ],
            'service' => [
                'command' => 'make:class',
                'path' => "App/Modules/{$module}/Services/{$name}",
            ],
            'model' => [
                'command' => 'make:model',
                'path' => "App/Modules/{$module}/Models/{$name}",
            ],
            default => null,
        };

        // Báo lỗi nếu người dùng gõ sai loại file
        if (!$mapping) {
            $this->error("❌ Loại file [{$type}] không hợp lệ!");
            $this->line("👉 Chỉ chấp nhận: <info>controller</info>, <info>request</info>, <info>service</info>, <info>resource</info>, <info>model</info>.");
            return Command::FAILURE;
        }

        // 3. Thực thi lệnh gốc của Laravel ở bên dưới
        $this->info(" Đang khởi tạo {$type} cho Module [{$module}]...");
        
        Artisan::call($mapping['command'], [
            'name' => $mapping['path'],
        ]);

        // 4. In thông báo thành công ra màn hình
        $this->line(trim(Artisan::output()));
        return Command::SUCCESS;
    }
}