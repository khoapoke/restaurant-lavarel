<?php

namespace Tests\Feature;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RestaurantSeeder::class);
        config(['services.payment_webhook.secret' => self::SECRET, 'services.payment_webhook.allow_unsigned' => false]);
    }

    private function pendingQrOrder(): Order
    {
        return Order::query()
            ->where('trang_thai', OrderStatus::PENDING_CONFIRMATION->value)
            ->where('loai_phuc_vu', FulfillmentType::PICKUP->value)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function sendWebhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    public function test_signed_webhook_marks_the_payment_paid_and_confirms_the_order(): void
    {
        $order = $this->pendingQrOrder();

        $this->sendWebhook([
            'content' => "DH{$order->ma_don_hang} THANH TOAN",
            'transferAmount' => (float) $order->tong_tien,
            'transactionCode' => 'FT-SEPAY-0001',
        ])->assertOk()->assertJsonPath('data.status', 'PAID');

        $this->assertDatabaseHas('THANH_TOAN', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => PaymentStatus::PAID->value,
            'ma_giao_dich' => 'FT-SEPAY-0001',
        ]);
        $this->assertDatabaseHas('DON_HANG', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => OrderStatus::CONFIRMED->value,
        ]);
    }

    public function test_webhook_with_an_invalid_signature_is_rejected_without_changes(): void
    {
        $order = $this->pendingQrOrder();

        $this->sendWebhook([
            'orderId' => $order->ma_don_hang,
            'amount' => (float) $order->tong_tien,
        ], 'forged-signature')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid webhook signature');

        $this->assertDatabaseHas('THANH_TOAN', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => PaymentStatus::UNPAID->value,
        ]);
    }

    public function test_webhook_is_refused_when_no_secret_is_configured(): void
    {
        config(['services.payment_webhook.secret' => null]);
        $order = $this->pendingQrOrder();

        $this->sendWebhook(['orderId' => $order->ma_don_hang, 'amount' => (float) $order->tong_tien], 'anything')
            ->assertStatus(503);

        $this->assertDatabaseHas('THANH_TOAN', [
            'ma_don_hang' => $order->ma_don_hang,
            'trang_thai' => PaymentStatus::UNPAID->value,
        ]);
    }

    public function test_underpaid_transfer_is_logged_but_not_marked_paid(): void
    {
        $order = $this->pendingQrOrder();

        $this->sendWebhook([
            'orderId' => $order->ma_don_hang,
            'amount' => (float) $order->tong_tien - 1000,
        ])->assertBadRequest();

        $payment = $order->payment()->firstOrFail();
        $this->assertSame(PaymentStatus::UNPAID, $payment->trang_thai);
        $this->assertStringContainsString('PAID_AMOUNT_TOO_LOW', (string) $payment->log_callback);
    }

    public function test_webhook_requires_a_paid_amount(): void
    {
        $order = $this->pendingQrOrder();

        $this->sendWebhook(['orderId' => $order->ma_don_hang])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Webhook payload must include a positive paid amount');
    }
}
