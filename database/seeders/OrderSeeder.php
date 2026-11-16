<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Account;
use App\Modules\Core\Enums\OrderStatus;
use App\Modules\Core\Enums\PaymentMethod;
use App\Modules\Core\Enums\PaymentStatus;
use App\Modules\Menu\Models\Dish;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng DON_HANG, CHI_TIET_DON_HANG, THANH_TOAN (Tier 2 - Dữ liệu giao dịch mẫu, phụ thuộc TAI_KHOAN & MON_AN).
     */
    public function run(): void
    {
        $accKh1 = Account::where('ten_dang_nhap', 'kh1')->first();
        $accKh2 = Account::where('ten_dang_nhap', 'kh2')->first();

        if (! $accKh1 || ! $accKh2) {
            return;
        }

        $dishPhoBo = Dish::where('ten_mon_an', 'Phở bò đặc biệt')->first();
        $dishTraDao = Dish::where('ten_mon_an', 'Trà đào cam sả thanh mát')->first();
        $dishComTam = Dish::where('ten_mon_an', 'Cơm tấm sườn bì chả trứng')->first();

        if (! $dishPhoBo || ! $dishTraDao || ! $dishComTam) {
            return;
        }

        // Đơn 1: Đã hoàn thành (COMPLETED) của kh1
        $order1 = Order::create([
            'ngay_dat_hang' => Carbon::now()->subDays(2),
            'tong_tien' => (79000 * 2) + 38000, // 196.000
            'trang_thai' => OrderStatus::COMPLETED,
            'dia_chi_giao_hang' => '[DELIVERY] Lê Hoàng Hùng - 0923456789 | 789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
            'ma_tai_khoan' => $accKh1->ma_tai_khoan,
        ]);

        $order1->dishes()->attach([
            $dishPhoBo->ma_mon_an => ['so_luong' => 2, 'don_gia' => 79000],
            $dishTraDao->ma_mon_an => ['so_luong' => 1, 'don_gia' => 38000],
        ]);

        Payment::create([
            'so_tien' => 196000,
            'phuong_thuc' => PaymentMethod::CASH,
            'trang_thai' => PaymentStatus::PAID,
            'ma_don_hang' => $order1->ma_don_hang,
            'ma_giao_dich' => 'CASH-' . $order1->ma_don_hang,
        ]);

        // Đơn 2: Chờ xác nhận (PENDING_CONFIRMATION) của kh2
        $order2 = Order::create([
            'ngay_dat_hang' => Carbon::now()->subMinutes(15),
            'tong_tien' => 65000,
            'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
            'dia_chi_giao_hang' => '[PICKUP] Nhận tại nhà hàng - KH: Phạm Thị Mai - SĐT: 0934567890',
            'ma_tai_khoan' => $accKh2->ma_tai_khoan,
        ]);

        $order2->dishes()->attach([
            $dishComTam->ma_mon_an => ['so_luong' => 1, 'don_gia' => 65000],
        ]);

        Payment::create([
            'so_tien' => 65000,
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
            'trang_thai' => PaymentStatus::UNPAID,
            'ma_don_hang' => $order2->ma_don_hang,
            'ma_giao_dich' => 'QR' . date('Ymd') . '0002',
        ]);
    }
}

