<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\ReservationStatus;
use App\Modules\Core\Enums\RoleType;
use App\Modules\Core\Enums\TableStatus;
use App\Modules\Reservation\Models\DiningTable;
use App\Modules\Reservation\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReservationDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function createCustomerUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::CUSTOMER->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'cust_user_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'cust_' . uniqid() . '@example.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('test_token')->plainTextToken;

        return [$account, $token];
    }

    protected function createAdminUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::ADMIN->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'admin_user_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'admin_' . uniqid() . '@example.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('admin_token')->plainTextToken;

        return [$account, $token];
    }

    protected function createTable(int $capacity = 8, string $name = 'Bàn Lớn'): DiningTable
    {
        return DiningTable::create([
            'ten_ban' => $name . '_' . uniqid(),
            'suc_chua' => $capacity,
            'vi_tri' => 'Tầng 1',
            'trang_thai' => TableStatus::AVAILABLE,
        ]);
    }

    /**
     * Test reservation requires deposit for >= 6 guests during peak hours (11:30 - 13:30 or 18:00 - 20:30).
     */
    public function test_reservation_requires_deposit_for_6_or_more_guests_during_peak_hours(): void
    {
        [$account, $token] = $this->createCustomerUser();
        $table = $this->createTable(10);

        // Đặt bàn vào lúc 12:00 (giờ trưa cao điểm)
        $bookingTime = now()->addDays(2)->setTime(12, 0, 0);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customer/reservations', [
                'tableId' => $table->ma_ban,
                'reservationTime' => $bookingTime->format('Y-m-d H:i:s'),
                'guestCount' => 6,
                'note' => 'Đặt tiệc gia đình',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'code' => 201,
                'data' => [
                    'status' => 'PENDING_DEPOSIT',
                    'depositAmount' => 300000,
                    'isDepositPaid' => false,
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotNull($data['depositDeadline']);
        $this->assertNotNull($data['qrCodeUrl']);
        $this->assertStringContainsString('amount=300000', $data['qrCodeUrl']);
        $this->assertStringContainsString('DB' . $data['reservationId'], $data['qrCodeUrl']);
    }

    /**
     * Test reservation is free (deposit = 0) during peak hours if guest count < 6.
     */
    public function test_reservation_free_during_peak_hours_if_less_than_6_guests(): void
    {
        [$account, $token] = $this->createCustomerUser();
        $table = $this->createTable(4);

        $bookingTime = now()->addDays(2)->setTime(19, 0, 0);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customer/reservations', [
                'tableId' => $table->ma_ban,
                'reservationTime' => $bookingTime->format('Y-m-d H:i:s'),
                'guestCount' => 4,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'PENDING',
                    'depositAmount' => 0,
                    'isDepositPaid' => false,
                    'depositDeadline' => null,
                    'qrCodeUrl' => null,
                ],
            ]);
    }

    /**
     * Test reservation is free (deposit = 0) during non-peak hours even if >= 6 guests.
     */
    public function test_reservation_free_during_non_peak_hours_even_if_6_or_more_guests(): void
    {
        [$account, $token] = $this->createCustomerUser();
        $table = $this->createTable(10);

        // 15:00 là khung giờ thấp điểm (không thuộc 11:30-13:30 hoặc 18:00-20:30)
        $bookingTime = now()->addDays(2)->setTime(15, 0, 0);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customer/reservations', [
                'tableId' => $table->ma_ban,
                'reservationTime' => $bookingTime->format('Y-m-d H:i:s'),
                'guestCount' => 8,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'PENDING',
                    'depositAmount' => 0,
                    'isDepositPaid' => false,
                ],
            ]);
    }

    /**
     * Test mock deposit payment API marks reservation CONFIRMED and updates table status.
     */
    public function test_customer_mock_deposit_success_confirms_reservation(): void
    {
        [$account, $token] = $this->createCustomerUser();
        $table = $this->createTable(8);

        $bookingTime = now()->addDays(2)->setTime(18, 30, 0);
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customer/reservations', [
                'tableId' => $table->ma_ban,
                'reservationTime' => $bookingTime->format('Y-m-d H:i:s'),
                'guestCount' => 6,
            ]);

        $reservationId = $createRes->json('data.reservationId');

        // Customer calls mock-deposit
        $mockRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/customer/reservations/{$reservationId}/mock-deposit");

        $mockRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'reservationId' => $reservationId,
                    'status' => 'CONFIRMED',
                    'isDepositPaid' => true,
                ],
            ]);

        $this->assertEquals(TableStatus::RESERVED, $table->fresh()->trang_thai);
    }

    /**
     * Test mock deposit fails and reservation is cancelled if 15-minute window expired.
     */
    public function test_customer_mock_deposit_fails_if_expired(): void
    {
        [$account, $token] = $this->createCustomerUser();
        $table = $this->createTable(8);

        $bookingTime = now()->addDays(2)->setTime(19, 0, 0);
        $reservation = Reservation::create([
            'thoi_gian' => $bookingTime,
            'so_luong_khach' => 6,
            'trang_thai' => ReservationStatus::PENDING_DEPOSIT,
            'ma_tai_khoan' => $account->ma_tai_khoan,
            'ma_ban' => $table->ma_ban,
            'tien_coc' => 300000,
            'da_thanh_toan_coc' => false,
            'han_chot_coc' => now()->subMinutes(1), // Đã hết hạn
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/customer/reservations/{$reservation->ma_dat_ban}/mock-deposit");

        $response->assertStatus(400);

        $this->assertEquals(ReservationStatus::CANCELLED, $reservation->fresh()->trang_thai);
    }

    /**
     * Test admin/staff can confirm deposit manually (BKG-14).
     */
    public function test_admin_confirm_deposit_manually(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$admin, $adminToken] = $this->createAdminUser();
        $table = $this->createTable(8);

        $bookingTime = now()->addDays(2)->setTime(12, 30, 0);
        $reservation = Reservation::create([
            'thoi_gian' => $bookingTime,
            'so_luong_khach' => 6,
            'trang_thai' => ReservationStatus::PENDING_DEPOSIT,
            'ma_tai_khoan' => $customer->ma_tai_khoan,
            'ma_ban' => $table->ma_ban,
            'tien_coc' => 300000,
            'da_thanh_toan_coc' => false,
            'han_chot_coc' => now()->addMinutes(15),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}/confirm-deposit");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'reservationId' => $reservation->ma_dat_ban,
                    'status' => 'CONFIRMED',
                    'isDepositPaid' => true,
                    'depositAmount' => 300000,
                ],
            ]);

        $this->assertEquals(TableStatus::RESERVED, $table->fresh()->trang_thai);
    }

    /**
     * Test payment webhook automatically confirms reservation deposit.
     */
    public function test_webhook_confirms_reservation_deposit(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        $table = $this->createTable(8);

        $bookingTime = now()->addDays(2)->setTime(18, 30, 0);
        $reservation = Reservation::create([
            'thoi_gian' => $bookingTime,
            'so_luong_khach' => 6,
            'trang_thai' => ReservationStatus::PENDING_DEPOSIT,
            'ma_tai_khoan' => $customer->ma_tai_khoan,
            'ma_ban' => $table->ma_ban,
            'tien_coc' => 300000,
            'da_thanh_toan_coc' => false,
            'han_chot_coc' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/v1/customer/payments/webhook', [
            'content' => "DB{$reservation->ma_dat_ban} COC",
            'amount' => 300000,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'reservationId' => $reservation->ma_dat_ban,
                    'status' => 'CONFIRMED',
                    'isDepositPaid' => true,
                ],
            ]);

        $this->assertTrue((bool) $reservation->fresh()->da_thanh_toan_coc);
        $this->assertEquals(ReservationStatus::CONFIRMED, $reservation->fresh()->trang_thai);
    }

    /**
     * Test expired PENDING_DEPOSIT reservation does not block table for new reservations.
     */
    public function test_expired_deposit_reservation_does_not_block_table(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        $table = $this->createTable(8);

        $bookingTime = now()->addDays(2)->setTime(19, 0, 0);

        // Tạo 1 đơn PENDING_DEPOSIT đã quá 15 phút (hết hạn)
        Reservation::create([
            'thoi_gian' => $bookingTime,
            'so_luong_khach' => 6,
            'trang_thai' => ReservationStatus::PENDING_DEPOSIT,
            'ma_tai_khoan' => $customer->ma_tai_khoan,
            'ma_ban' => $table->ma_ban,
            'tien_coc' => 300000,
            'da_thanh_toan_coc' => false,
            'han_chot_coc' => now()->subMinute(), // Hết hạn
        ]);

        // Kiểm tra xem bàn có hiển thị trong danh sách bàn trống không
        $availableRes = $this->getJson('/api/v1/customer/tables/available?date=' . $bookingTime->toDateString() . '&time=19:00&guestCount=6');
        $availableRes->assertStatus(200);

        $tableIds = collect($availableRes->json('data'))->pluck('tableId')->all();
        $this->assertContains($table->ma_ban, $tableIds);

        // Khách khác đặt bàn này vào giờ này sẽ thành công (không bị lỗi 409)
        [$customer2, $token2] = $this->createCustomerUser();
        $newBookingRes = $this->withHeader('Authorization', "Bearer {$token2}")
            ->postJson('/api/v1/customer/reservations', [
                'tableId' => $table->ma_ban,
                'reservationTime' => $bookingTime->format('Y-m-d H:i:s'),
                'guestCount' => 6,
            ]);

        $newBookingRes->assertStatus(201);
    }

    /**
     * Test admin can update reservation status to OCCUPIED and table automatically syncs to OCCUPIED.
     */
    public function test_admin_can_update_reservation_to_occupied_and_sync_table(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$admin, $adminToken] = $this->createAdminUser();
        $table = $this->createTable(8);

        $bookingTime = now()->addDays(1)->setTime(12, 0, 0);
        $reservation = Reservation::create([
            'thoi_gian' => $bookingTime,
            'so_luong_khach' => 4,
            'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => $customer->ma_tai_khoan,
            'ma_ban' => $table->ma_ban,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}/status", [
                'targetStatus' => 'OCCUPIED',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'OCCUPIED',
                    'tableStatus' => 'OCCUPIED',
                ],
            ]);

        $this->assertEquals(ReservationStatus::OCCUPIED, $reservation->fresh()->trang_thai);
        $this->assertEquals(TableStatus::OCCUPIED, $table->fresh()->trang_thai);
    }
}
