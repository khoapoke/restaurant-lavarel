<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\OrderStatus;
use App\Modules\Core\Enums\PaymentMethod;
use App\Modules\Core\Enums\PaymentStatus;
use App\Modules\Core\Enums\RoleType;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManualPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function createCustomerUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::CUSTOMER->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'cust_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'cust_' . uniqid() . '@example.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('test_token')->plainTextToken;

        return [$account, $token];
    }

    protected function createStaffUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::STAFF->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'staff_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'staff_' . uniqid() . '@restaurant.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('staff_token')->plainTextToken;

        return [$account, $token];
    }

    protected function createAdminUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::ADMIN->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'admin_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'admin_' . uniqid() . '@restaurant.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('admin_token')->plainTextToken;

        return [$account, $token];
    }

    protected function createTestOrder(Account $customer, float $total = 250000): array
    {
        $order = Order::create([
            'ngay_dat_hang' => now(),
            'tong_tien' => $total,
            'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
            'dia_chi_giao_hang' => '123 Đường Test, Quận 1',
            'ma_tai_khoan' => $customer->ma_tai_khoan,
        ]);

        $payment = Payment::create([
            'so_tien' => $total,
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
            'trang_thai' => PaymentStatus::UNPAID,
            'ma_don_hang' => $order->ma_don_hang,
        ]);

        return [$order, $payment];
    }

    /**
     * Test staff can manually confirm payment by paymentId (PAY-03).
     */
    public function test_staff_can_manually_confirm_payment_by_payment_id(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$staff, $staffToken] = $this->createStaffUser();
        [$order, $payment] = $this->createTestOrder($customer, 180000);

        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm", [
                'note' => 'Đã thấy tiền vào tài khoản Vietcombank',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'paymentId' => $payment->ma_thanh_toan,
                    'orderId' => $order->ma_don_hang,
                    'status' => 'PAID',
                    'orderStatus' => 'CONFIRMED',
                ],
            ]);

        $this->assertEquals(PaymentStatus::PAID, $payment->fresh()->trang_thai);
        $this->assertEquals(OrderStatus::CONFIRMED, $order->fresh()->trang_thai);
        $this->assertStringContainsString('Vietcombank', $payment->fresh()->log_callback);
    }

    /**
     * Test staff can manually confirm payment by orderId (ORD-13).
     */
    public function test_staff_can_manually_confirm_payment_by_order_id(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$staff, $staffToken] = $this->createStaffUser();
        [$order, $payment] = $this->createTestOrder($customer, 320000);

        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/confirm-payment", [
                'note' => 'Khách chuyển khoản đúng cú pháp',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'orderId' => $order->ma_don_hang,
                    'paymentId' => $payment->ma_thanh_toan,
                    'paymentStatus' => 'PAID',
                    'orderStatus' => 'CONFIRMED',
                ],
            ]);

        $this->assertEquals(PaymentStatus::PAID, $payment->fresh()->trang_thai);
        $this->assertEquals(OrderStatus::CONFIRMED, $order->fresh()->trang_thai);
    }

    /**
     * Test customer can use mock payment API for quick test.
     */
    public function test_customer_can_mock_order_payment(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$order, $payment] = $this->createTestOrder($customer, 150000);

        $response = $this->withHeader('Authorization', "Bearer {$custToken}")
            ->postJson("/api/v1/customer/payments/{$order->ma_don_hang}/mock-payment");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'orderId' => $order->ma_don_hang,
                    'status' => 'PAID',
                    'orderStatus' => 'CONFIRMED',
                    'isPaid' => true,
                ],
            ]);

        $this->assertEquals(PaymentStatus::PAID, $payment->fresh()->trang_thai);
        $this->assertEquals(OrderStatus::CONFIRMED, $order->fresh()->trang_thai);
    }

    /**
     * Test manual confirmation satisfies order completion requirement.
     */
    public function test_manual_payment_allows_order_to_complete(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$staff, $staffToken] = $this->createStaffUser();
        [$order, $payment] = $this->createTestOrder($customer, 500000);

        // 1. Staff confirms payment
        $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/confirm-payment")
            ->assertStatus(200);

        // 2. Advance status: CONFIRMED -> PREPARING -> READY -> DELIVERING -> COMPLETED
        $statuses = ['PREPARING', 'READY', 'DELIVERING', 'COMPLETED'];
        foreach ($statuses as $st) {
            $res = $this->withHeader('Authorization', "Bearer {$staffToken}")
                ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", [
                    'targetStatus' => $st,
                ]);
            $res->assertStatus(200);
        }

        $this->assertEquals(OrderStatus::COMPLETED, $order->fresh()->trang_thai);
    }

    /**
     * Test cannot confirm an already refunded payment.
     */
    public function test_cannot_confirm_refunded_payment(): void
    {
        [$customer, $custToken] = $this->createCustomerUser();
        [$staff, $staffToken] = $this->createStaffUser();
        [$order, $payment] = $this->createTestOrder($customer, 200000);

        $payment->trang_thai = PaymentStatus::REFUNDED;
        $payment->save();

        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm");

        $response->assertStatus(400);
    }
}

