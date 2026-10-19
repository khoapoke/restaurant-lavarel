<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RoleType;
use App\Models\Account;
use App\Models\Cart;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Register a new customer account (USR-01).
     *
     * @param array $data
     * @return array
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // 1. Create UserProfile (NGUOI_DUNG)
            $userProfile = UserProfile::create([
                'ho_ten' => $data['fullName'],
                'so_dien_thoai' => $data['phoneNumber'],
                'ngay_sinh' => $data['birthDate'] ?? null,
                'dia_chi' => $data['address'] ?? null,
                'di_ung' => [],
                'che_do_an' => [],
                'so_dia_chi' => !empty($data['address']) ? [$data['address']] : [],
            ]);

            // 2. Resolve Customer Role
            $customerRole = Role::firstOrCreate(
                ['ten_vai_tro' => RoleType::CUSTOMER->value]
            );

            // 3. Create Account (TAI_KHOAN)
            $account = Account::create([
                'ten_dang_nhap' => $data['username'],
                'mat_khau' => Hash::make($data['password']),
                'email' => $data['email'],
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $userProfile->ma_nguoi_dung,
                'ma_vai_tro' => $customerRole->ma_vai_tro,
            ]);

            // 4. Create Cart (GIO_HANG)
            $cart = Cart::create([
                'ngay_tao' => now(),
                'ma_tai_khoan' => $account->ma_tai_khoan,
            ]);

            // 5. Generate Sanctum Personal Access Token
            $token = $account->createToken('restaurant_api_token')->plainTextToken;

            // 6. Eager load relations for resource output
            $account->load(['role', 'userProfile', 'cart']);

            return [
                'account' => $account,
                'accessToken' => $token,
                'tokenType' => 'Bearer',
            ];
        });
    }
}
