<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\UserProfile;
use Illuminate\Database\Seeder;

class UserProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng NGUOI_DUNG (Tier 0 - Độc lập, không chứa khóa ngoại).
     */
    public function run(): void
    {
        $profiles = [
            [
                'ho_ten' => 'Nguyễn Văn Quản Trị',
                'ngay_sinh' => '1990-01-01',
                'dia_chi' => '123 Đường Lê Lợi, Quận 1, TP.HCM',
                'so_dien_thoai' => '0901234567',
                'di_ung' => [],
                'che_do_an' => [],
                'so_dia_chi' => ['123 Đường Lê Lợi, Quận 1, TP.HCM'],
            ],
            [
                'ho_ten' => 'Trần Thị Thu Ngân',
                'ngay_sinh' => '1995-05-10',
                'dia_chi' => '456 Đường Nguyễn Huệ, Quận 1, TP.HCM',
                'so_dien_thoai' => '0912345678',
                'di_ung' => [],
                'che_do_an' => [],
                'so_dia_chi' => ['456 Đường Nguyễn Huệ, Quận 1, TP.HCM'],
            ],
            [
                'ho_ten' => 'Lê Hoàng Hùng',
                'ngay_sinh' => '1998-03-20',
                'dia_chi' => '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
                'so_dien_thoai' => '0923456789',
                'di_ung' => ['PEANUT', 'SHELLFISH'], // Dị ứng đậu phộng, hải sản vỏ
                'che_do_an' => ['KETO'],
                'so_dia_chi' => [
                    '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
                    'Tòa nhà Bitexco, Bến Nghé, Quận 1, TP.HCM',
                ],
            ],
            [
                'ho_ten' => 'Phạm Thị Mai',
                'ngay_sinh' => '2001-11-12',
                'dia_chi' => '12 Võ Văn Tần, Quận 3, TP.HCM',
                'so_dien_thoai' => '0934567890',
                'di_ung' => [],
                'che_do_an' => ['VEGETARIAN'],
                'so_dia_chi' => ['12 Võ Văn Tần, Quận 3, TP.HCM'],
            ],
            [
                'ho_ten' => 'Đỗ Vi Phạm',
                'ngay_sinh' => '1992-07-07',
                'dia_chi' => '99 Xô Viết Nghệ Tĩnh, Bình Thạnh, TP.HCM',
                'so_dien_thoai' => '0945678901',
                'di_ung' => [],
                'che_do_an' => [],
                'so_dia_chi' => ['99 Xô Viết Nghệ Tĩnh, Bình Thạnh, TP.HCM'],
            ],
        ];

        foreach ($profiles as $profile) {
            UserProfile::firstOrCreate(
                ['so_dien_thoai' => $profile['so_dien_thoai']],
                $profile
            );
        }
    }
}

