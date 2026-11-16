<?php

namespace Database\Seeders;

use App\Modules\Core\Enums\TableStatus;
use App\Modules\Reservation\Models\DiningTable;
use Illuminate\Database\Seeder;

class DiningTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng BAN_AN (Tier 0 - Độc lập, không chứa khóa ngoại).
     */
    public function run(): void
    {
        $tables = [
            [
                'ten_ban' => 'Bàn T01',
                'suc_chua' => 2,
                'trang_thai' => TableStatus::AVAILABLE,
                'vi_tri' => 'Tầng 1 - Cạnh cửa sổ sân vườn',
            ],
            [
                'ten_ban' => 'Bàn T02',
                'suc_chua' => 4,
                'trang_thai' => TableStatus::AVAILABLE,
                'vi_tri' => 'Tầng 1 - Khu trung tâm',
            ],
            [
                'ten_ban' => 'Bàn T03',
                'suc_chua' => 4,
                'trang_thai' => TableStatus::RESERVED,
                'vi_tri' => 'Tầng 1 - Khu trung tâm',
            ],
            [
                'ten_ban' => 'Bàn T04',
                'suc_chua' => 6,
                'trang_thai' => TableStatus::AVAILABLE,
                'vi_tri' => 'Tầng 2 - Sân thượng view thoáng',
            ],
            [
                'ten_ban' => 'Bàn VIP01',
                'suc_chua' => 10,
                'trang_thai' => TableStatus::OCCUPIED,
                'vi_tri' => 'Tầng 2 - Phòng máy lạnh riêng',
            ],
            [
                'ten_ban' => 'Bàn T05',
                'suc_chua' => 4,
                'trang_thai' => TableStatus::MAINTENANCE,
                'vi_tri' => 'Tầng 1 - Khu góc vườn (Đang sửa điện)',
            ],
        ];

        foreach ($tables as $tbl) {
            DiningTable::firstOrCreate(
                ['ten_ban' => $tbl['ten_ban']],
                $tbl
            );
        }
    }
}

