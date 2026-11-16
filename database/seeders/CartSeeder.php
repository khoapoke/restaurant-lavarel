<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Account;
use App\Modules\Cart\Models\Cart;
use App\Modules\Menu\Models\Dish;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng GIO_HANG & MON_AN_GIO_HANG (Tier 2 - Dữ liệu giao dịch mẫu, phụ thuộc TAI_KHOAN & MON_AN).
     */
    public function run(): void
    {
        $accKh1 = Account::where('ten_dang_nhap', 'kh1')->first();
        $accKh2 = Account::where('ten_dang_nhap', 'kh2')->first();
        $accKhLocked = Account::where('ten_dang_nhap', 'kh_locked')->first();

        if (! $accKh1 || ! $accKh2 || ! $accKhLocked) {
            return;
        }

        // Tạo giỏ hàng 1:1 cho từng tài khoản
        $cartKh1 = Cart::firstOrCreate(
            ['ma_tai_khoan' => $accKh1->ma_tai_khoan],
            ['ngay_tao' => Carbon::now()]
        );

        $cartKh2 = Cart::firstOrCreate(
            ['ma_tai_khoan' => $accKh2->ma_tai_khoan],
            ['ngay_tao' => Carbon::now()]
        );

        $cartKhLocked = Cart::firstOrCreate(
            ['ma_tai_khoan' => $accKhLocked->ma_tai_khoan],
            ['ngay_tao' => Carbon::now()]
        );

        // Thêm món ăn mẫu vào giỏ hàng của kh1
        $dishPhoBo = Dish::where('ten_mon_an', 'Phở bò đặc biệt')->first();
        $dishTraDao = Dish::where('ten_mon_an', 'Trà đào cam sả thanh mát')->first();

        if ($dishPhoBo && $dishTraDao) {
            $cartKh1->dishes()->syncWithoutDetaching([
                $dishPhoBo->ma_mon_an => ['so_luong' => 2],
                $dishTraDao->ma_mon_an => ['so_luong' => 1],
            ]);
        }
    }
}

