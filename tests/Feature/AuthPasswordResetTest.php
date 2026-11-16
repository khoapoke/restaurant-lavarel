<?php

namespace Tests\Feature;

use App\Modules\Auth\Mail\ResetPasswordOtpMail;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\UserProfile;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\RoleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nạp role và tài khoản mẫu
        $roleCustomer = Role::firstOrCreate(['ten_vai_tro' => RoleType::CUSTOMER->value]);
        $profile = UserProfile::create([
            'ho_ten' => 'Người Dùng Test OTP',
            'so_dien_thoai' => '0988776655',
        ]);

        Account::create([
            'ten_dang_nhap' => 'user_otp_test',
            'email' => 'user_otp_test@example.com',
            'mat_khau' => Hash::make('OldPassword@123'),
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profile->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);

        Account::create([
            'ten_dang_nhap' => 'locked_user_test',
            'email' => 'locked_user@example.com',
            'mat_khau' => Hash::make('Password@123'),
            'trang_thai' => AccountStatus::LOCKED,
            'ma_nguoi_dung' => $profile->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);
    }

    /**
     * Test USR-07: Forgot password sends dynamic email with 6-digit OTP.
     */
    public function test_forgot_password_sends_email_with_otp_successfully(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'user_otp_test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);

        // Kiểm tra OTP đã được lưu vào bảng password_reset_tokens
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'user_otp_test@example.com',
        ]);

        // Kiểm tra Mail được gửi đến đúng email người dùng (động, không hardcode)
        Mail::assertSent(ResetPasswordOtpMail::class, function ($mail) {
            return $mail->hasTo('user_otp_test@example.com')
                && strlen($mail->otp) === 6
                && is_numeric($mail->otp);
        });
    }

    /**
     * Test USR-07: Forgot password with non-existent email returns 404.
     */
    public function test_forgot_password_with_non_existent_email_returns_404(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'code' => 404,
            ]);

        Mail::assertNothingSent();
    }

    /**
     * Test USR-07: Forgot password with locked account returns 403.
     */
    public function test_forgot_password_with_locked_account_returns_403(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'locked_user@example.com',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 403,
            ]);

        Mail::assertNothingSent();
    }

    /**
     * Test USR-08: Reset password with valid OTP updates password and allows login.
     */
    public function test_reset_password_with_valid_otp_updates_password_successfully(): void
    {
        $otp = '654321';
        DB::table('password_reset_tokens')->insert([
            'email' => 'user_otp_test@example.com',
            'token' => Hash::make($otp),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user_otp_test@example.com',
            'otp' => $otp,
            'password' => 'NewPassword@2026',
            'password_confirmation' => 'NewPassword@2026',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);

        // Token OTP phải bị xóa sau khi sử dụng
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'user_otp_test@example.com',
        ]);

        // Thử đăng nhập lại với mật khẩu mới
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'user_otp_test',
            'password' => 'NewPassword@2026',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ]);
    }

    /**
     * Test USR-08: Reset password with wrong OTP returns 400.
     */
    public function test_reset_password_with_wrong_otp_returns_400(): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => 'user_otp_test@example.com',
            'token' => Hash::make('111111'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user_otp_test@example.com',
            'otp' => '999999', // Sai mã
            'password' => 'NewPassword@2026',
            'password_confirmation' => 'NewPassword@2026',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'code' => 400,
            ]);
    }

    /**
     * Test USR-08: Reset password with expired OTP (>15 minutes) returns 400.
     */
    public function test_reset_password_with_expired_otp_returns_400(): void
    {
        $otp = '123456';
        DB::table('password_reset_tokens')->insert([
            'email' => 'user_otp_test@example.com',
            'token' => Hash::make($otp),
            'created_at' => Carbon::now()->subMinutes(16), // Đã quá 15 phút
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user_otp_test@example.com',
            'otp' => $otp,
            'password' => 'NewPassword@2026',
            'password_confirmation' => 'NewPassword@2026',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'code' => 400,
            ]);

        // Bản ghi hết hạn phải bị xóa
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'user_otp_test@example.com',
        ]);
    }

    /**
     * Test Validation: Invalid email or OTP format returns 422.
     */
    public function test_reset_password_validation_fails_with_invalid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'not-an-email',
            'otp' => '12', // Not 6 chars
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 422,
            ]);
    }
}

