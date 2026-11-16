<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RestaurantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Điều phối toàn bộ seeder theo đúng thứ tự ràng buộc khóa ngoại (Foreign Keys).
     */
    public function run(): void
    {
        // -------------------------------------------------------------
        // NHÓM 1: MASTER DATA (Bắt buộc để hệ thống vận hành)
        // -------------------------------------------------------------

        // 1.1. Tier 0: Bảng độc lập (Không có khóa ngoại)
        $this->call([
            RoleSeeder::class,        // 1. Bảng VAI_TRO
            UserProfileSeeder::class, // 2. Bảng NGUOI_DUNG
            CategorySeeder::class,    // 3. Bảng DANH_MUC
            DiningTableSeeder::class, // 4. Bảng BAN_AN
        ]);

        // 1.2. Tier 1: Bảng phụ thuộc vào Tier 0
        $this->call([
            AccountSeeder::class,     // 5. Bảng TAI_KHOAN (FK -> VAI_TRO, NGUOI_DUNG)
            DishSeeder::class,        // 6. Bảng MON_AN    (FK -> DANH_MUC)
        ]);

        // -------------------------------------------------------------
        // NHÓM 2: TRANSACTION DATA (Dữ liệu mẫu - Tùy chọn)
        // Có thể comment tắt nhóm này khi muốn database sạch để test thao tác trên UI
        // -------------------------------------------------------------

        // 2.1. Tier 2: Bảng giao dịch phụ thuộc vào Tier 1
        $this->call([
            CartSeeder::class,        // 7. Bảng GIO_HANG, MON_AN_GIO_HANG      (FK -> TAI_KHOAN, MON_AN)
            OrderSeeder::class,       // 8. Bảng DON_HANG, CHI_TIET, THANH_TOAN (FK -> TAI_KHOAN, MON_AN)
            ReservationSeeder::class, // 9. Bảng DAT_BAN                        (FK -> TAI_KHOAN, BAN_AN)
        ]);
    }
}
