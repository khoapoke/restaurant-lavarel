<?php

namespace Tests\Feature;

use App\Enums\MoneySource;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Cart;
use App\Models\Dish;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Staff\KitchenService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Giao hàng / tự lấy: giao thất bại, giao lại, mã nhận hàng, sửa người nhận, hủy có
 * quyết định tiền, giờ hẹn hợp lệ và xác nhận lại khi giá đổi (ADR-026).
 */
class DeliveryExceptionApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'delivery-webhook-secret';

    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        config(['services.payment_webhook.secret' => self::SECRET]);
        $this->seed(RestaurantSeeder::class);
    }

    /** Đăng nhập một lần cho mỗi vai trò; xóa guard đã nhớ để mỗi request dùng đúng người. */
    private function as(string $username): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->tokens[$username] ??= $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $username === 'kh2' ? 'Password@123' : '123'])->assertOk()->json('data.accessToken');
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$username]);
    }

    private function fillCart(string $username = 'kh1'): void
    {
        $account = Account::where('ten_dang_nhap', $username)->firstOrFail();
        $cart = Cart::firstOrCreate(['ma_tai_khoan' => $account->ma_tai_khoan]);
        $dish = Dish::where('con_ban', true)->orderBy('ma_mon_an')->firstOrFail();
        $cart->dishes()->syncWithoutDetaching([$dish->ma_mon_an => ['so_luong' => 2]]);
    }

    /** @param array<string, mixed> $overrides */
    private function checkout(string $type, string $method, array $overrides = []): Order
    {
        $this->fillCart();
        $orderId = $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', [
            'fulfillmentType' => $type,
            'recipientName' => 'Khách Thử Ngoại Lệ',
            'recipientPhone' => '0911222333',
            'shippingAddress' => $type === 'DELIVERY' ? '12 Lý Tự Trọng, Quận 1' : null,
            'paymentMethod' => $method,
            ...$overrides,
        ])->assertCreated()->json('data.orderId');

        return Order::findOrFail($orderId);
    }

    private function makeReady(Order $order): void
    {
        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->as('admin')->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", ['targetStatus' => $status])->assertOk();
        }
    }

    private function payByTransfer(Order $order, string $reference): void
    {
        $this->flushHeaders();
        $body = json_encode(['orderId' => $order->ma_don_hang, 'amount' => (float) $order->fresh()->tong_tien, 'transactionCode' => $reference], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/webhooks/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk()->assertJsonPath('data.status', 'APPLIED');
    }

    private function fulfil(Order $order, array $payload)
    {
        return $this->as('admin')->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/fulfillment-status", $payload);
    }

    public function test_a_failed_cash_delivery_is_not_marked_paid_and_can_be_redelivered(): void
    {
        $order = $this->checkout('DELIVERY', 'CASH');
        $this->makeReady($order);
        $this->fulfil($order, ['targetStatus' => 'OUT_FOR_DELIVERY'])->assertOk()->assertJsonPath('data.deliveryAttempts', 1);

        $this->fulfil($order, ['targetStatus' => 'DELIVERY_FAILED'])->assertUnprocessable()->assertJsonValidationErrors(['failureReason', 'note']);
        $this->fulfil($order, ['targetStatus' => 'DELIVERY_FAILED', 'failureReason' => 'CUSTOMER_UNREACHABLE', 'note' => 'Gọi 3 lần không nghe'])
            ->assertOk()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.fulfillmentStatus', 'DELIVERY_FAILED')
            ->assertJsonPath('data.deliveryFailure.reason', 'CUSTOMER_UNREACHABLE')
            ->assertJsonPath('data.payment.status', 'UNPAID')
            ->assertJsonPath('data.availableActions.canRedeliver', true)
            ->assertJsonPath('data.availableActions.canCancel', true);
        $this->assertSame(0, MoneyTransaction::where('ma_don_hang_muc_tieu', $order->ma_don_hang)->count());

        $this->fulfil($order, ['targetStatus' => 'AWAITING_DRIVER', 'note' => 'Khách hẹn giao lại 13h'])->assertOk();
        $this->fulfil($order, ['targetStatus' => 'OUT_FOR_DELIVERY'])->assertOk()->assertJsonPath('data.deliveryAttempts', 2);
        $this->fulfil($order, ['targetStatus' => 'DELIVERED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.payment.status', 'PAID');

        $cash = MoneyTransaction::where('ma_don_hang_muc_tieu', $order->ma_don_hang)->sole();
        $this->assertSame(MoneySource::COD, $cash->nguon);
        $this->assertEquals((float) $order->tong_tien, (float) $cash->so_tien);
        $this->assertNotNull($cash->ma_nguoi_ghi_nhan);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'ORDER_FULFILLMENT_CHANGED', 'ma_doi_tuong' => $order->ma_don_hang]);
    }

    public function test_an_order_on_the_road_cannot_be_cancelled_until_the_failure_is_recorded(): void
    {
        $order = $this->checkout('DELIVERY', 'BANK_TRANSFER_QR');
        $this->makeReady($order);
        $this->payByTransfer($order, 'FT26DLV0001');
        $this->fulfil($order, ['targetStatus' => 'OUT_FOR_DELIVERY'])->assertOk();

        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", ['cancelReason' => 'Khách không nhận'])->assertStatus(409);

        $this->fulfil($order, ['targetStatus' => 'DELIVERY_FAILED', 'failureReason' => 'DRIVER_CANCELLED', 'note' => 'Tài xế hủy chuyến'])->assertOk();
        // Lỗi không thuộc về khách: không được giữ tiền.
        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Không còn tài xế', 'reasonType' => 'DELIVERY_FAILED', 'moneyDecision' => 'RETAINED',
        ])->assertUnprocessable();

        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Không còn tài xế', 'reasonType' => 'DELIVERY_FAILED',
        ])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation.moneyDecision', 'REFUND_DUE')
            ->assertJsonPath('data.availableActions.requiresRefund', true)
            ->assertJsonPath('data.money.refundDue', fn ($value) => (float) $value === (float) $order->tong_tien);

        $this->assertEquals((float) $order->tong_tien, app(PaymentLedgerService::class)->refundDue($order->fresh()));
        $this->assertSame(1, $this->as('admin')->getJson('/api/v1/admin/reports/dashboard')->json('data.attention.refundsPending'));
    }

    public function test_money_is_kept_only_when_the_customer_refused_the_delivery(): void
    {
        $order = $this->checkout('DELIVERY', 'BANK_TRANSFER_QR');
        $this->makeReady($order);
        $this->payByTransfer($order, 'FT26DLV0002');
        $this->fulfil($order, ['targetStatus' => 'OUT_FOR_DELIVERY'])->assertOk();
        $this->fulfil($order, ['targetStatus' => 'DELIVERY_FAILED', 'failureReason' => 'CUSTOMER_REFUSED', 'note' => 'Khách từ chối nhận tại cửa'])
            ->assertOk()->assertJsonPath('data.availableActions.canRetainMoney', true);

        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Khách từ chối nhận hàng', 'reasonType' => 'DELIVERY_FAILED', 'moneyDecision' => 'RETAINED',
        ])->assertOk()
            ->assertJsonPath('data.cancellation.moneyDecision', 'RETAINED')
            ->assertJsonPath('data.availableActions.requiresRefund', false);

        $this->assertSame(0.0, app(PaymentLedgerService::class)->refundDue($order->fresh()));
    }

    public function test_pickup_is_handed_over_only_after_the_code_or_phone_matches(): void
    {
        $order = $this->checkout('PICKUP', 'CASH');
        $code = $order->ma_nhan_hang;
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $code);

        $this->as('kh1')->getJson("/api/v1/customer/orders/{$order->ma_don_hang}")->assertJsonPath('data.pickupCode', $code);
        $this->as('admin')->getJson("/api/v1/admin/orders/{$order->ma_don_hang}")
            ->assertJsonMissingPath('data.pickupCode')
            ->assertJsonPath('data.hasPickupCode', true);
        $this->makeReady($order);

        $this->fulfil($order, ['targetStatus' => 'COLLECTED'])->assertUnprocessable();
        $this->fulfil($order, ['targetStatus' => 'COLLECTED', 'pickupCode' => $code === '0000' ? '1111' : '0000'])->assertUnprocessable();
        $this->assertSame(OrderStatus::READY, $order->fresh()->trang_thai);

        $this->fulfil($order, ['targetStatus' => 'COLLECTED', 'verifiedPhone' => '+84911222333', 'collectedBy' => 'Trần Văn Lấy Hộ'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.collectedBy', 'Trần Văn Lấy Hộ')
            ->assertJsonPath('data.payment.status', 'PAID');
    }

    public function test_an_uncollected_pickup_becomes_overdue_and_can_be_closed_as_a_no_show(): void
    {
        $order = $this->checkout('PICKUP', 'BANK_TRANSFER_QR');
        $this->makeReady($order);
        $this->payByTransfer($order, 'FT26PCK0001');

        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Khách không đến lấy', 'reasonType' => 'PICKUP_NO_SHOW', 'moneyDecision' => 'RETAINED',
        ])->assertStatus(409);

        $this->travel(61)->minutes();
        $this->as('admin')->getJson("/api/v1/admin/orders/{$order->ma_don_hang}")
            ->assertJsonPath('data.handoff.pickupOverdue', true)
            ->assertJsonPath('data.availableActions.canMarkPickupNoShow', true);
        $this->assertSame(1, $this->as('admin')->getJson('/api/v1/admin/reports/dashboard')->json('data.attention.pickupOverdue'));

        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Khách không đến lấy sau 60 phút', 'reasonType' => 'PICKUP_NO_SHOW', 'moneyDecision' => 'RETAINED',
        ])->assertOk()->assertJsonPath('data.cancellation.moneyDecision', 'RETAINED');
    }

    public function test_a_driver_waiting_too_long_is_flagged(): void
    {
        $order = $this->checkout('DELIVERY', 'CASH');
        $this->makeReady($order);

        $this->travel(21)->minutes();
        $this->as('admin')->getJson("/api/v1/admin/orders/{$order->ma_don_hang}")
            ->assertJsonPath('data.handoff.driverWaitTooLong', true)
            ->assertJsonPath('data.handoff.waitMinutes', 21);
        $this->assertSame(1, $this->as('admin')->getJson('/api/v1/admin/reports/dashboard')->json('data.attention.driverWaitTooLong'));
    }

    public function test_the_customer_can_fix_the_address_until_the_driver_leaves(): void
    {
        $order = $this->checkout('DELIVERY', 'CASH');

        $this->as('kh1')->patchJson("/api/v1/customer/orders/{$order->ma_don_hang}/recipient", ['shippingAddress' => '99 Hai Bà Trưng, Quận 1', 'recipientPhone' => '0988777666'])
            ->assertOk()
            ->assertJsonPath('data.shippingAddress', '99 Hai Bà Trưng, Quận 1')
            ->assertJsonPath('data.canEditRecipient', true);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'ORDER_RECIPIENT_CHANGED', 'ma_doi_tuong' => $order->ma_don_hang]);

        $this->as('kh2')->patchJson("/api/v1/customer/orders/{$order->ma_don_hang}/recipient", ['shippingAddress' => 'Địa chỉ lạ'])->assertNotFound();
        $this->as('admin')->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/recipient", ['recipientName' => 'Người nhận mới'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->makeReady($order);
        $this->fulfil($order, ['targetStatus' => 'OUT_FOR_DELIVERY'])->assertOk();

        $this->as('kh1')->patchJson("/api/v1/customer/orders/{$order->ma_don_hang}/recipient", ['shippingAddress' => '1 Lê Lợi'])->assertStatus(409);
        $this->assertSame('99 Hai Bà Trưng, Quận 1', $order->fresh()->dia_chi_giao_hang);
    }

    public function test_a_cash_order_cannot_switch_to_qr_once_it_is_on_the_road(): void
    {
        $order = $this->checkout('DELIVERY', 'CASH');
        $this->makeReady($order);
        $this->fulfil($order, ['targetStatus' => 'OUT_FOR_DELIVERY'])->assertOk();

        $this->as('kh1')->postJson("/api/v1/customer/payments/{$order->ma_don_hang}/qr")->assertStatus(409);
        $this->fulfil($order, ['targetStatus' => 'DELIVERED'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_an_unserviceable_address_is_cancelled_with_a_refund_due(): void
    {
        $order = $this->checkout('DELIVERY', 'BANK_TRANSFER_QR');
        $this->payByTransfer($order, 'FT26DLV0003');

        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Địa chỉ ngoài phạm vi giao', 'reasonType' => 'ADDRESS_UNSERVICEABLE', 'moneyDecision' => 'RETAINED',
        ])->assertUnprocessable();
        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", [
            'cancelReason' => 'Địa chỉ ngoài phạm vi giao', 'reasonType' => 'ADDRESS_UNSERVICEABLE',
        ])->assertOk()->assertJsonPath('data.cancellation.reason', 'Địa chỉ ngoài phạm vi giao');

        $this->as('kh1')->getJson("/api/v1/customer/orders/{$order->ma_don_hang}")
            ->assertJsonPath('data.cancellation.moneyDecision', 'REFUND_DUE')
            ->assertJsonPath('data.money.refundDue', fn ($value) => (float) $value === (float) $order->tong_tien);
    }

    public function test_scheduled_times_must_fall_inside_ordering_hours(): void
    {
        $this->fillCart();
        $base = ['fulfillmentType' => 'PICKUP', 'recipientName' => 'Khách Hẹn', 'recipientPhone' => '0911222333', 'paymentMethod' => 'CASH'];

        $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', [...$base, 'scheduledAt' => now()->setTime(22, 30)->toDateTimeString()])->assertUnprocessable();
        $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', [...$base, 'scheduledAt' => now()->addDays(8)->setTime(12, 0)->toDateTimeString()])->assertUnprocessable();

        $this->travelTo(now()->setTime(22, 15));
        $this->tokens = []; // token đăng nhập hết hạn sau 8 giờ
        $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', $base)->assertStatus(409);
        $orderId = $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', [...$base, 'scheduledAt' => now()->addDay()->setTime(11, 0)->toDateTimeString()])
            ->assertCreated()->assertJsonPath('data.isAsap', false)->json('data.orderId');

        // Đơn hẹn chỉ xuống bếp từ giờ hẹn − 30 phút.
        $this->as('admin')->patchJson("/api/v1/admin/orders/{$orderId}/status", ['targetStatus' => 'CONFIRMED'])->assertOk();
        $this->assertFalse(app(KitchenService::class)->activeItems()->contains('ma_don_hang', $orderId));
        $this->assertTrue(app(KitchenService::class)->upcoming()->contains('ma_don_hang', $orderId));
        $this->travelTo(now()->addDay()->setTime(10, 31));
        $this->assertTrue(app(KitchenService::class)->activeItems()->contains('ma_don_hang', $orderId));
    }

    public function test_checkout_asks_for_confirmation_when_a_price_changed(): void
    {
        $this->fillCart();
        $dish = Dish::where('con_ban', true)->orderBy('ma_mon_an')->firstOrFail();
        $cartTotal = (float) $this->as('kh1')->getJson('/api/v1/customer/cart')->json('data.estimatedTotalAmount');
        $this->assertGreaterThan(0, $cartTotal);
        $dish->update(['gia_tien' => (float) $dish->gia_tien + 5000]);

        $base = ['fulfillmentType' => 'PICKUP', 'recipientName' => 'Khách Giá', 'recipientPhone' => '0911222333', 'paymentMethod' => 'CASH'];
        $response = $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', [...$base, 'expectedTotal' => $cartTotal])
            ->assertStatus(409)
            ->assertJsonPath('errors.reason', 'PRICE_CHANGED');
        $this->assertGreaterThan($cartTotal, $response->json('errors.currentTotal'));
        $this->assertGreaterThan(0, Cart::where('ma_tai_khoan', Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'))->firstOrFail()->dishes()->count());

        $this->as('kh1')->postJson('/api/v1/customer/orders/checkout', [...$base, 'expectedTotal' => $response->json('errors.currentTotal')])
            ->assertCreated()
            ->assertJsonPath('data.totalAmount', $response->json('errors.currentTotal'));
    }
}
