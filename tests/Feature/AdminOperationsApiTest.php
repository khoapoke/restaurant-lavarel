<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RestaurantSeeder::class);
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => '123',
        ])->assertOk()->json('data.accessToken');
    }

    public function test_admin_can_view_the_operational_dashboard_and_manage_tables(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/v1/admin/reports/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'period', 'revenue', 'orders', 'topSellingDishes', 'reservations', 'operations',
            ]]);

        $created = $this->withToken($token)->postJson('/api/v1/admin/tables', [
            'tableName' => 'Bàn sân vườn 09',
            'capacity' => 6,
            'location' => 'Tầng 1 - Sân vườn',
        ])->assertCreated()->assertJsonPath('data.status', 'AVAILABLE');

        $tableId = $created->json('data.tableId');
        $this->withToken($token)->putJson("/api/v1/admin/tables/{$tableId}", [
            'capacity' => 8,
            'status' => 'MAINTENANCE',
        ])->assertOk()
            ->assertJsonPath('data.capacity', 8)
            ->assertJsonPath('data.status', 'MAINTENANCE');
    }

    public function test_admin_can_advance_an_online_order_through_valid_states(): void
    {
        $token = $this->adminToken();
        $order = Order::query()->where('trang_thai', OrderStatus::PENDING_CONFIRMATION->value)->firstOrFail();

        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->withToken($token)
                ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", ['targetStatus' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => OrderStatus::READY->value,
        ]);
    }

    public function test_admin_completes_pickup_after_customer_collects_and_settles_cash(): void
    {
        $token = $this->adminToken();
        $order = Order::query()->where('loai_phuc_vu', 'PICKUP')->firstOrFail();
        $order->payment->update(['phuong_thuc' => PaymentMethod::CASH]);

        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->withToken($token)
                ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", ['targetStatus' => $status])
                ->assertOk();
        }

        $this->withToken($token)
            ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/fulfillment-status", [
                'targetStatus' => 'COLLECTED',
            ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.fulfillmentStatus', 'COLLECTED')
            ->assertJsonPath('data.payment.status', 'PAID');

        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => OrderStatus::COMPLETED->value,
            'trang_thai_giao_nhan' => FulfillmentStatus::COLLECTED->value,
        ]);
    }

    public function test_admin_tracks_delivery_handoff_before_completing_order(): void
    {
        $token = $this->adminToken();
        $order = Order::query()->where('loai_phuc_vu', 'DELIVERY')->firstOrFail();
        $order->update([
            'trang_thai' => OrderStatus::READY,
            'trang_thai_giao_nhan' => FulfillmentStatus::AWAITING_DRIVER,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/fulfillment-status", [
                'targetStatus' => 'OUT_FOR_DELIVERY',
            ])->assertOk()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.fulfillmentStatus', 'OUT_FOR_DELIVERY');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/fulfillment-status", [
                'targetStatus' => 'DELIVERED',
            ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.fulfillmentStatus', 'DELIVERED');
    }

    public function test_admin_cannot_handoff_an_unpaid_qr_delivery_to_the_driver(): void
    {
        $token = $this->adminToken();
        $order = Order::query()->where('loai_phuc_vu', 'DELIVERY')->firstOrFail();
        $order->update([
            'trang_thai' => OrderStatus::READY,
            'trang_thai_giao_nhan' => FulfillmentStatus::AWAITING_DRIVER,
        ]);
        $order->payment->update([
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
            'trang_thai' => PaymentStatus::UNPAID,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/fulfillment-status", [
                'targetStatus' => 'OUT_FOR_DELIVERY',
            ])->assertBadRequest()
            ->assertJsonPath('message', 'QR bank transfer must be paid before handing the order to the driver');

        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => OrderStatus::READY->value,
            'trang_thai_giao_nhan' => FulfillmentStatus::AWAITING_DRIVER->value,
        ]);
    }

    public function test_admin_can_confirm_a_reservation_and_refund_a_paid_payment(): void
    {
        $token = $this->adminToken();
        $reservation = Reservation::query()->where('trang_thai', ReservationStatus::PENDING->value)->firstOrFail();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}/status", [
                'targetStatus' => 'CONFIRMED',
                'adminNote' => 'Đã gọi xác nhận với khách',
            ])->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $payment = Payment::query()->where('trang_thai', PaymentStatus::PAID->value)->firstOrFail();
        $this->withToken($token)
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/refund", [
                'refundReason' => 'Khách đổi kế hoạch, quản lý duyệt hoàn tiền',
            ])->assertOk()
            ->assertJsonPath('data.status', 'REFUNDED');

        $this->assertDatabaseHas('THANH_TOAN', [
            'ma_thanh_toan' => $payment->ma_thanh_toan,
            'trang_thai' => PaymentStatus::REFUNDED->value,
        ]);
    }
}
