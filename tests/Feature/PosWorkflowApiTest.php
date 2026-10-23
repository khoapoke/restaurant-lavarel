<?php

namespace Tests\Feature;

use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Dish;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Giờ cố định trong giờ nhận gọi món/đặt bàn (config/restaurant.php).
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => 'staff1',
            'password' => '123',
        ])->assertOk()->json('data.accessToken');
    }

    public function test_complete_pos_flow_from_table_to_payment_and_close(): void
    {
        $token = $this->token();
        $table = DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->firstOrFail();
        $dish = Dish::where('con_ban', true)->where('hien_thi', true)->firstOrFail();

        $sessionId = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'workflow-open-session-0001')
            ->postJson('/api/v1/staff/pos/sessions', [
                'tableIds' => [$table->ma_ban],
                'guestCount' => 2,
            ])->assertCreated()->json('data.sessionId');

        $orderId = $this->withToken($token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/orders", ['note' => 'Khách thử POS'])
            ->assertCreated()->json('data.orderId');

        $itemId = $this->withToken($token)
            ->postJson("/api/v1/staff/pos/orders/{$orderId}/items", [
                'dishId' => $dish->ma_mon_an,
                'quantity' => 1,
                'note' => 'Ít cay',
                'clientRequestId' => 'workflow-add-item-0001',
            ])->assertCreated()
            ->assertJsonPath('data.kitchenStatus', 'DRAFT')
            ->json('data.itemId');

        $this->withToken($token)
            ->patchJson("/api/v1/staff/pos/order-items/{$itemId}", ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.quantity', 2);

        $this->withToken($token)
            ->postJson("/api/v1/staff/pos/orders/{$orderId}/send-kitchen", ['itemIds' => [$itemId]])
            ->assertOk()
            ->assertJsonPath('data.order.items.0.kitchenStatus', 'SENT');

        $secondDish = Dish::where('con_ban', true)
            ->where('hien_thi', true)
            ->where('ma_mon_an', '!=', $dish->ma_mon_an)
            ->firstOrFail();
        $secondItemId = $this->withToken($token)
            ->postJson("/api/v1/staff/pos/orders/{$orderId}/items", [
                'dishId' => $secondDish->ma_mon_an,
                'quantity' => 1,
                'note' => 'Gọi thêm sau lượt đầu',
                'clientRequestId' => 'workflow-add-item-0002',
            ])->assertCreated()
            ->assertJsonPath('data.kitchenStatus', 'DRAFT')
            ->json('data.itemId');

        $this->withToken($token)
            ->postJson("/api/v1/staff/pos/orders/{$orderId}/send-kitchen", ['itemIds' => [$secondItemId]])
            ->assertOk();

        foreach (['QUEUED', 'PREPARING', 'READY'] as $status) {
            $this->withToken($token)
                ->patchJson("/api/v1/staff/kitchen/items/{$itemId}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
            $this->withToken($token)
                ->patchJson("/api/v1/staff/kitchen/items/{$secondItemId}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $invoice = $this->withToken($token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")
            ->assertCreated();
        $invoiceId = $invoice->json('data.invoiceId');
        $total = $invoice->json('data.totalAmount');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'workflow-payment-0001')
            ->postJson("/api/v1/staff/invoices/{$invoiceId}/payments", [
                'method' => 'CASH',
                'amount' => $total,
            ])->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.status', 'PAID');

        $session = $this->withToken($token)
            ->getJson("/api/v1/staff/pos/sessions/{$sessionId}")
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/close", [
                'expectedVersion' => $session->json('data.version'),
            ])->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.tables.0.status', 'CLEANING');
    }
}
