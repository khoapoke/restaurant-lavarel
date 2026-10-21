<?php

namespace App\Console\Commands;

use App\Services\Dining\LiveDiningDemoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('dining:demo {--rotate : Issue a new QR token for the demo table} {--json : Print the result as JSON}')]
#[Description('Reset the Mộc Live Dining demo table and print its QR link (local/testing only)')]
class PrepareLiveDiningDemo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LiveDiningDemoService $demo): int
    {
        try {
            $result = $demo->prepare((bool) $this->option('rotate'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $table = $result['table'];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'tableId' => (int) $table->ma_ban,
                'tableName' => $table->ten_ban,
                'tableToken' => $table->ma_qr,
                'url' => $result['url'],
                'closedSessions' => $result['closedSessions'],
                'cancelledSessions' => $result['cancelledSessions'],
                'expiredCheckIns' => $result['expiredCheckIns'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Bàn demo {$table->ten_ban} đã sẵn sàng.");
        $this->components->twoColumnDetail('Link QR cho khách', $result['url']);
        $this->components->twoColumnDetail('Phiên đã đóng / đã hủy', "{$result['closedSessions']} / {$result['cancelledSessions']}");
        $this->components->twoColumnDetail('Yêu cầu nhận bàn hết hạn', (string) $result['expiredCheckIns']);
        $this->newLine();
        $this->line('  1. Laptop: đăng nhập staff1 / 123, mở Sơ đồ bàn (/staff/floor).');
        $this->line('  2. Điện thoại hoặc tab mobile: mở link QR ở trên.');
        $this->line('  3. Khách bấm "Yêu cầu nhận bàn", POS bấm "Nhận khách".');
        $this->line('  4. Khách gọi món, Bếp (/staff/kitchen) chuyển trạng thái, khách thấy cập nhật.');

        return self::SUCCESS;
    }
}
