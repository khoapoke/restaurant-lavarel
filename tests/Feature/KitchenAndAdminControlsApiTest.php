<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\MoneyFlag;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenAndAdminControlsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
    }

    private function login(string $username, string $password = '123'): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ])->assertOk()->json('data.accessToken');
    }

    /** Đơn tự lấy của seeder, hẹn trong 20 phút nên đã tới giờ xuống bếp (giờ hẹn − 30 phút). */
    private function pendingPickupOrder(): Order
    {
        $order = Order::query()
            ->where('trang_thai', OrderStatus::PENDING_CONFIRMATION->value)
            ->where('loai_phuc_vu', FulfillmentType::PICKUP->value)
            ->firstOrFail();
        $order->update(['thoi_gian_nhan_du_kien' => now()->addMinutes(20)]);

        return $order->fresh();
    }

    public function test_kitchen_board_hides_unconfirmed_online_orders_and_shows_confirmed_ones(): void
    {
        $order = $this->pendingPickupOrder();
        $staffToken = $this->login('staff1');

        $board = $this->withToken($staffToken)->getJson('/api/v1/staff/kitchen/items')->assertOk();
        $this->assertNotContains($order->ma_don_hang, collect($board->json('data.items'))->pluck('orderId'));

        $order->update(['trang_thai' => OrderStatus::CONFIRMED]);

        $this->withToken($staffToken)->getJson('/api/v1/staff/kitchen/items')
            ->assertOk()
            ->assertJsonFragment(['orderId' => $order->ma_don_hang, 'fulfillmentType' => 'PICKUP', 'channel' => 'ONLINE']);
    }

    public function test_kitchen_cannot_start_an_unconfirmed_online_order(): void
    {
        $item = OrderItem::where('ma_don_hang', $this->pendingPickupOrder()->ma_don_hang)->firstOrFail();

        $this->withToken($this->login('staff1'))
            ->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => 'QUEUED'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Online orders must be confirmed before the kitchen can start.');
    }

    public function test_finishing_every_online_item_makes_the_order_ready_for_handoff(): void
    {
        $order = $this->pendingPickupOrder();
        $order->update(['trang_thai' => OrderStatus::CONFIRMED]);
        $staffToken = $this->login('staff1');

        foreach (OrderItem::where('ma_don_hang', $order->ma_don_hang)->get() as $item) {
            foreach (['QUEUED', 'PREPARING', 'READY'] as $status) {
                $this->withToken($staffToken)
                    ->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => $status])
                    ->assertOk();
            }

            $this->withToken($staffToken)
                ->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => 'SERVED'])
                ->assertStatus(409);
        }

        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => OrderStatus::READY->value,
            'trang_thai_giao_nhan' => FulfillmentStatus::AWAITING_CUSTOMER->value,
        ]);
    }

    public function test_admin_cannot_drive_a_dine_in_order_outside_the_pos(): void
    {
        $posOrder = Order::where('loai_phuc_vu', FulfillmentType::DINE_IN->value)->firstOrFail();
        $token = $this->login('admin');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/orders/{$posOrder->ma_don_hang}/status", ['targetStatus' => 'READY'])
            ->assertStatus(409);
        $this->withToken($token)
            ->postJson("/api/v1/admin/orders/{$posOrder->ma_don_hang}/cancel", ['cancelReason' => 'Thử hủy từ quản trị'])
            ->assertStatus(409);

        $this->assertDatabaseHas('DON_HANG', ['ma_don_hang' => $posOrder->ma_don_hang, 'trang_thai' => OrderStatus::PREPARING->value]);
    }

    public function test_admin_cancellation_withdraws_items_from_the_kitchen(): void
    {
        $order = $this->pendingPickupOrder();
        $token = $this->login('admin');
        $this->withToken($token)
            ->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", ['targetStatus' => 'CONFIRMED'])
            ->assertOk()
            ->assertJsonPath('data.availableActions.nextStatus', 'PREPARING');

        $this->withToken($token)
            ->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", ['cancelReason' => 'Hết nguyên liệu chính'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.availableActions.canCancel', false);

        $this->assertSame(0, OrderItem::where('ma_don_hang', $order->ma_don_hang)
            ->where('trang_thai_bep', '!=', KitchenItemStatus::CANCELLED->value)->count());
    }

    public function test_admin_confirms_a_bank_transfer_manually(): void
    {
        $order = $this->pendingPickupOrder();
        $payment = $order->payment()->firstOrFail();
        $token = $this->login('admin');

        $this->withToken($token)
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transactionCode']);

        $this->withToken($token)
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm", [
                'transactionCode' => 'FT26102100123',
                'note' => 'Đã kiểm tra trên ứng dụng ngân hàng',
            ])->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.orderStatus', 'CONFIRMED');

        $this->assertDatabaseHas('THANH_TOAN', [
            'ma_thanh_toan' => $payment->ma_thanh_toan,
            'trang_thai' => PaymentStatus::PAID->value,
            'ma_giao_dich' => 'FT26102100123',
        ]);

        // Cùng mã đã ghi: không ghi lại.
        $this->withToken($token)
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT26102100123'])
            ->assertStatus(409);

        // Khoản chuyển thật thứ hai (mã khác) cho đơn đã đủ tiền: được lưu sổ, chờ hoàn, không tăng tiền bán hàng.
        $this->withToken($token)
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT26102100124'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');
        $second = MoneyTransaction::where('ma_tham_chieu', 'FT26102100124')->sole();
        $this->assertSame(MoneyFlag::DUPLICATE, $second->canh_bao);
        $this->assertSame(ReconciliationStatus::NEEDS_REVIEW, $second->trang_thai_doi_soat);
        $this->assertEquals((float) $second->so_tien, $second->unallocatedAmount());
        $this->assertEquals((float) $order->fresh()->tong_tien, (float) $payment->fresh()->so_tien_da_thu);
    }

    public function test_staff_cannot_use_admin_payment_confirmation(): void
    {
        $payment = $this->pendingPickupOrder()->payment()->firstOrFail();

        $this->withToken($this->login('staff1'))
            ->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT26102100123'])
            ->assertForbidden();
    }

    public function test_admin_payment_filter_accepts_the_api_method_name(): void
    {
        $this->withToken($this->login('admin'))
            ->getJson('/api/v1/admin/payments?method=BANK_TRANSFER_QR')
            ->assertOk()
            ->assertJsonMissing(['paymentMethod' => 'CASH'])
            ->assertJsonFragment(['paymentMethod' => 'BANK_TRANSFER_QR']);
    }

    public function test_occupied_and_reserved_states_cannot_be_set_by_hand(): void
    {
        $table = DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->firstOrFail();
        $token = $this->login('admin');

        foreach (['OCCUPIED', 'RESERVED'] as $status) {
            $this->withToken($token)
                ->putJson("/api/v1/admin/tables/{$table->ma_ban}", ['status' => $status])
                ->assertUnprocessable()
                ->assertJsonPath('message', "{$status} is managed automatically by reservations and POS sessions");
        }

        $this->assertDatabaseHas('BAN_AN', ['ma_ban' => $table->ma_ban, 'trang_thai' => TableStatus::AVAILABLE->value]);
    }

    public function test_cancelling_the_only_confirmed_reservation_today_releases_the_table(): void
    {
        $reservation = Reservation::where('trang_thai', ReservationStatus::CONFIRMED->value)->firstOrFail();
        $this->assertSame(TableStatus::RESERVED, $reservation->diningTable->trang_thai);

        $this->withToken($this->login('admin'))
            ->patchJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}/status", [
                'targetStatus' => 'CANCELLED',
                'adminNote' => 'Khách gọi điện báo hủy',
            ])->assertOk();

        $this->assertDatabaseHas('BAN_AN', ['ma_ban' => $reservation->ma_ban, 'trang_thai' => TableStatus::AVAILABLE->value]);
    }

    public function test_customer_moving_a_confirmed_reservation_sends_it_back_for_confirmation(): void
    {
        $reservation = Reservation::where('trang_thai', ReservationStatus::CONFIRMED->value)->firstOrFail();
        $token = $this->login('kh1');

        $this->withToken($token)->getJson('/api/v1/customer/reservations')
            ->assertOk()
            ->assertJsonFragment(['reservationId' => $reservation->ma_dat_ban, 'canModify' => true]);

        $this->withToken($token)->putJson("/api/v1/customer/reservations/{$reservation->ma_dat_ban}", [
            'reservationTime' => now()->addDays(3)->setTime(19, 0)->format('Y-m-d H:i:s'),
        ])->assertOk()
            ->assertJsonPath('data.status', 'PENDING');

        $this->assertDatabaseHas('BAN_AN', ['ma_ban' => $reservation->ma_ban, 'trang_thai' => TableStatus::AVAILABLE->value]);
    }
}
