<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RoleType;
use App\Models\Account;
use App\Models\Cart;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    /**
     * Authenticate account and return Sanctum token (USR-02).
     *
     * @param array $credentials
     * @return array
     * @throws AuthenticationException
     * @throws HttpException
     */
    public function login(array $credentials): array
    {
        $username = $credentials['username'];
        $plainPassword = $credentials['password'];
        $rememberMe = (bool) ($credentials['rememberMe'] ?? false);

        // STEP 1: Truy vấn tài khoản theo 'ten_dang_nhap'
        $account = Account::with(['role', 'userProfile', 'cart'])
            ->where('ten_dang_nhap', $username)
            ->first();

        if (!$account) {
            throw new AuthenticationException('Invalid credentials');
        }

        // STEP 2: Kiểm tra mật khẩu (hỗ trợ cả BCrypt và legacy plaintext '123' từ file SQL mẫu)
        $storedPassword = $account->mat_khau;
        $isPasswordValid = false;

        if (str_starts_with($storedPassword, '$2y$') || str_starts_with($storedPassword, '$2a$') || str_starts_with($storedPassword, '$2b$')) {
            $isPasswordValid = Hash::check($plainPassword, $storedPassword);
        } else {
            // Hỗ trợ mật khẩu dạng rõ ('123') và tự động mã hóa nâng cấp lên BCrypt trong DB
            if (hash_equals((string) $storedPassword, (string) $plainPassword)) {
                $isPasswordValid = true;
                $account->mat_khau = Hash::make($plainPassword);
                $account->save();
            }
        }

        if (!$isPasswordValid) {
            throw new AuthenticationException('Invalid credentials');
        }

        // STEP 3: Kiểm tra trạng thái khóa tài khoản (USR-09)
        if ($account->trang_thai === AccountStatus::LOCKED || $account->status === AccountStatus::LOCKED) {
            throw new HttpException(403, 'Your account has been locked by Administrator');
        }

        // STEP 4: Cấp phát Sanctum Personal Access Token kèm thời gian hết hạn
        $expiresAt = $rememberMe ? now()->addDays(30) : now()->addHours(8);
        $roleAbility = $account->role?->ten_vai_tro === RoleType::ADMIN->value ? 'role:admin' : 'role:customer';
        $token = $account->createToken('auth_token', [$roleAbility], $expiresAt);

        return [
            'account' => $account,
            'accessToken' => $token->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Revoke current access token (USR-03).
     *
     * @param Account $account
     * @return void
     */
    public function logout(?Account $account): void
    {
        $account?->currentAccessToken()?->delete();
    }
}
