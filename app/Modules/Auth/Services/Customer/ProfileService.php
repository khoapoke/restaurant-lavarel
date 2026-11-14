<?php

namespace App\Modules\Auth\Services\Customer;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProfileService
{
    /**
     * Get customer profile with related user profile data (USR-03).
     *
     * @param Account $account
     * @return Account
     */
    public function getProfile(Account $account): Account
    {
        return $account->load('userProfile');
    }

    /**
     * Update customer profile, addresses and allergies (USR-04, USR-05, USR-06).
     *
     * @param Account $account
     * @param array $data
     * @return Account
     */
    public function updateProfile(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data) {
            // 1. Cập nhật bảng TAI_KHOAN (email)
            if (isset($data['email'])) {
                $account->email = $data['email'];
                $account->save();
            }

            // 2. Tìm hoặc khởi tạo bản ghi NGUOI_DUNG
            $profile = $account->userProfile;
            if (!$profile) {
                $profile = UserProfile::create([
                    'ho_ten' => $data['fullName'] ?? $account->ten_dang_nhap,
                    'so_dien_thoai' => $data['phoneNumber'] ?? null,
                ]);
                $account->ma_nguoi_dung = $profile->ma_nguoi_dung;
                $account->save();
            }

            // 3. Cập nhật bảng NGUOI_DUNG (USR-04, USR-05, USR-06)
            if (isset($data['fullName'])) {
                $profile->ho_ten = $data['fullName'];
            }
            if (array_key_exists('birthDate', $data)) {
                $profile->ngay_sinh = $data['birthDate'];
            }
            if (isset($data['phoneNumber'])) {
                $profile->so_dien_thoai = $data['phoneNumber'];
            }
            if (array_key_exists('defaultAddress', $data)) {
                $profile->dia_chi = $data['defaultAddress'];
            }
            if (isset($data['savedAddresses'])) {
                $profile->so_dia_chi = $data['savedAddresses'];
            }
            if (isset($data['allergies'])) {
                $profile->di_ung = $data['allergies'];
            }
            if (isset($data['dietaryPreferences'])) {
                $profile->che_do_an = $data['dietaryPreferences'];
            }

            $profile->save();

            return $account->load('userProfile');
        });
    }

    /**
     * Change customer password (USR-06).
     *
     * @param Account $account
     * @param string $currentPassword
     * @param string $newPassword
     * @return void
     * @throws HttpException
     */
    public function changePassword(Account $account, string $currentPassword, string $newPassword): void
    {
        $stored = $account->mat_khau;
        $isValid = false;

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$2b$')) {
            $isValid = Hash::check($currentPassword, $stored);
        } else {
            $isValid = hash_equals((string) $stored, (string) $currentPassword);
        }

        if (!$isValid) {
            throw new HttpException(400, 'Current password does not match');
        }

        $account->mat_khau = Hash::make($newPassword);
        $account->save();

        // Thu hồi toàn bộ token cũ trừ token phiên hiện tại
        if ($account->currentAccessToken()) {
            $account->tokens()->where('id', '!=', $account->currentAccessToken()->id)->delete();
        }
    }
}

