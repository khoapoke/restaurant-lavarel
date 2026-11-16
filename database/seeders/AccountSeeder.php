<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\UserProfile;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\RoleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\Concerns\Has;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng TAI_KHOAN (Tier 1 - Phụ thuộc vào VAI_TRO và NGUOI_DUNG).
     */
    public function run(): void
    {
        // Tra cứu Roles
        $roleAdmin = Role::where('ten_vai_tro', RoleType::ADMIN->value)->firstOrFail();
        $roleStaff = Role::where('ten_vai_tro', RoleType::STAFF->value)->firstOrFail();
        $roleCustomer = Role::where('ten_vai_tro', RoleType::CUSTOMER->value)->firstOrFail();

        // Tra cứu UserProfiles theo số điện thoại
        $profileAdmin = UserProfile::where('so_dien_thoai', '0901234567')->firstOrFail();
        $profileStaff = UserProfile::where('so_dien_thoai', '0912345678')->firstOrFail();
        $profileKh1 = UserProfile::where('so_dien_thoai', '0923456789')->firstOrFail();
        $profileKh2 = UserProfile::where('so_dien_thoai', '0934567890')->firstOrFail();
        $profileKhLocked = UserProfile::where('so_dien_thoai', '0945678901')->firstOrFail();
        $profileUser1=UserProfile::where('so_dien_thoai', '0945678999')->firstOrFail();

        $accounts = [
            [
                'ten_dang_nhap' => 'admin',
                'mat_khau' => Hash::make('Password@123'),
                'email' => 'admin@restaurant.com',
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profileAdmin->ma_nguoi_dung,
                'ma_vai_tro' => $roleAdmin->ma_vai_tro,
            ],
            [
                'ten_dang_nhap' => 'admin1',
                'mat_khau' => Hash::make('123'),
                'email' => 'admin1@restaurant.com',
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profileAdmin->ma_nguoi_dung,
                'ma_vai_tro' => $roleAdmin->ma_vai_tro,
            ],
            [
                'ten_dang_nhap' => 'staff1',
                'mat_khau' => Hash::make('123'),
                'email' => 'staff1@restaurant.com',
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profileStaff->ma_nguoi_dung,
                'ma_vai_tro' => $roleStaff->ma_vai_tro,
            ],
            [
                'ten_dang_nhap' => 'kh1',
                'mat_khau' => Hash::make('123'),
                'email' => 'khachhang1@gmail.com',
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profileKh1->ma_nguoi_dung,
                'ma_vai_tro' => $roleCustomer->ma_vai_tro,
            ],
            [
                'ten_dang_nhap' => 'kh2',
                'mat_khau' => Hash::make('123'),
                'email' => 'khachhang2@gmail.com',
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profileKh2->ma_nguoi_dung,
                'ma_vai_tro' => $roleCustomer->ma_vai_tro,
            ],
            [
                'ten_dang_nhap' => 'kh_locked',
                'mat_khau' => Hash::make('123'),
                'email' => 'locked@gmail.com',
                'trang_thai' => AccountStatus::LOCKED,
                'ma_nguoi_dung' => $profileKhLocked->ma_nguoi_dung,
                'ma_vai_tro' => $roleCustomer->ma_vai_tro,
            ],
            [
                'ten_dang_nhap' => 'bian',
                'mat_khau' => Hash::make('123'),
                'email' => 'anomyous41@gmail.com',
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profileUser1->ma_nguoi_dung,
                'ma_vai_tro' => $roleCustomer->ma_vai_tro,
            ],
        ];

        foreach ($accounts as $account) {
            Account::firstOrCreate(
                ['ten_dang_nhap' => $account['ten_dang_nhap']],
                $account
            );
        }
    }
}

