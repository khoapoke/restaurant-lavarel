<?php

namespace Database\Seeders;

use App\Modules\Menu\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng DANH_MUC (Tier 0 - Độc lập, không chứa khóa ngoại).
     */
    public function run(): void
    {
        $categories = [
            [
                'ten_danh_muc' => 'Khai vị',
                'mo_ta' => 'Các món ăn nhẹ kích thích vị giác trước bữa chính',
                'thu_tu_hien_thi' => 1,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_danh_muc' => 'Món chính',
                'mo_ta' => 'Các món ăn truyền thống và hiện đại đậm đà hương vị Việt',
                'thu_tu_hien_thi' => 2,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_danh_muc' => 'Tráng miệng',
                'mo_ta' => 'Món ngọt, chè thanh nhiệt và bánh ngọt tươi',
                'thu_tu_hien_thi' => 3,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_danh_muc' => 'Đồ uống',
                'mo_ta' => 'Nước ép, trà hoa quả tươi và cà phê thơm ngon',
                'thu_tu_hien_thi' => 4,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['ten_danh_muc' => $cat['ten_danh_muc']],
                $cat
            );
        }
    }
}

