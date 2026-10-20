<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Models\Cart;
use App\Models\Order;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCheckoutFulfillmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RestaurantSeeder::class);
    }

    private function customerToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => 'kh1',
            'password' => '123',
        ])->assertOk()->json('data.accessToken');
    }

    public function test_delivery_checkout_creates_structured_fulfillment_and_returns_201(): void
    {
        $token = $this->customerToken();

        $response = $this->withToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'fulfillmentType' => 'DELIVERY',
            'recipientName' => 'Lê Hoàng Hùng',
            'recipientPhone' => '0923456789',
            'shippingAddress' => '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
            'paymentMethod' => 'CASH',
            'orderNote' => 'Gọi trước khi giao',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.fulfillmentType', 'DELIVERY')
            ->assertJsonPath('data.fulfillmentStatus', 'PENDING')
            ->assertJsonPath('data.recipientName', 'Lê Hoàng Hùng')
            ->assertJsonPath('data.shippingAddress', '789 Cách Mạng Tháng 8, Quận 10, TP.HCM')
            ->assertJsonPath('data.deliveryFee', 0);

        $orderId = $response->json('data.orderId');
        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $orderId,
            'loai_phuc_vu' => FulfillmentType::DELIVERY->value,
            'trang_thai_giao_nhan' => FulfillmentStatus::PENDING->value,
            'dia_chi_giao_hang' => '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
            'ten_nguoi_nhan' => 'Lê Hoàng Hùng',
        ]);

        $accountId = Order::query()->findOrFail($orderId)->ma_tai_khoan;
        $cart = Cart::query()->where('ma_tai_khoan', $accountId)->firstOrFail();
        $this->assertSame(0, $cart->dishes()->count());
    }

    public function test_pickup_checkout_accepts_future_collection_time_without_delivery_address(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $token = $this->customerToken();

        $response = $this->withToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'fulfillmentType' => 'PICKUP',
            'recipientName' => 'Lê Hoàng Hùng',
            'recipientPhone' => '0923456789',
            'scheduledAt' => '2026-10-20 13:00:00',
            'paymentMethod' => 'BANK_TRANSFER_QR',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.fulfillmentType', 'PICKUP')
            ->assertJsonPath('data.shippingAddress', null)
            ->assertJsonPath('data.scheduledAt', '2026-10-20T13:00:00');

        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $response->json('data.orderId'),
            'loai_phuc_vu' => FulfillmentType::PICKUP->value,
            'thoi_gian_nhan_du_kien' => '2026-10-20 13:00:00',
        ]);
    }

    public function test_checkout_returns_422_for_dine_in_or_legacy_takeaway_values(): void
    {
        $token = $this->customerToken();

        foreach (['DINE_IN', 'TAKEAWAY'] as $invalidType) {
            $this->withToken($token)->postJson('/api/v1/customer/orders/checkout', [
                'fulfillmentType' => $invalidType,
                'recipientName' => 'Lê Hoàng Hùng',
                'recipientPhone' => '0923456789',
                'paymentMethod' => 'CASH',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('fulfillmentType');
        }

        $this->assertSame(2, Cart::query()->firstOrFail()->dishes()->count());
    }
}
