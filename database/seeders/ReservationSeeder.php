<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Account;
use App\Modules\Core\Enums\ReservationStatus;
use App\Modules\Reservation\Models\DiningTable;
use App\Modules\Reservation\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ReservationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng DAT_BAN (Tier 2 - Dữ liệu giao dịch mẫu, phụ thuộc TAI_KHOAN & BAN_AN).
     */
    public function run(): void
    {
        $accKh1 = Account::where('ten_dang_nhap', 'kh1')->first();
        $accKh2 = Account::where('ten_dang_nhap', 'kh2')->first();
        $table3 = DiningTable::where('ten_ban', 'Bàn T03')->first();
        $table2 = DiningTable::where('ten_ban', 'Bàn T02')->first();

        if (! $accKh1 || ! $accKh2 || ! $table3 || ! $table2) {
            return;
        }

        // Phiếu đặt 1: kh1 đặt Bàn T03 vào ngày mai lúc 19:00 (CONFIRMED)
        Reservation::create([
            'thoi_gian' => Carbon::tomorrow()->setTime(19, 0),
            'so_luong_khach' => 4,
            'trang_thai' => ReservationStatus::CONFIRMED,
            'ghi_chu' => 'Tiệc kỷ niệm ngày cưới, chuẩn bị thêm nến thơm và ly rượu vang',
            'ma_tai_khoan' => $accKh1->ma_tai_khoan,
            'ma_ban' => $table3->ma_ban,
        ]);

        // Phiếu đặt 2: kh2 đặt Bàn T02 vào 2 ngày tới lúc 18:30 (PENDING)
        Reservation::create([
            'thoi_gian' => Carbon::now()->addDays(2)->setTime(18, 30),
            'so_luong_khach' => 2,
            'trang_thai' => ReservationStatus::PENDING,
            'ghi_chu' => 'Bàn gần cửa sổ, ăn chay nhẹ',
            'ma_tai_khoan' => $accKh2->ma_tai_khoan,
            'ma_ban' => $table2->ma_ban,
        ]);
    }
}

