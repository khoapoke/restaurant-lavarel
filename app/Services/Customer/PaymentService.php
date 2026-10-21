<?php

namespace App\Services\Customer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\VietQrService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function __construct(private readonly VietQrService $vietQrService) {}

    /**
     * Generate VietQR payment code for order (PAY-01).
     *
     * @return array<string, mixed>
     *
     * @throws HttpException
     */
    public function generateQr(int $accountId, int $orderId): array
    {
        return DB::transaction(function () use ($accountId, $orderId): array {
            $order = $this->findPayableOrder($accountId, $orderId);
            $payment = $this->lockOrCreatePayment($order);

            if ($payment->trang_thai === PaymentStatus::PAID) {
                throw new HttpException(409, 'Order has already been paid');
            }

            $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
            if (empty($payment->ma_giao_dich) || str_starts_with((string) $payment->ma_giao_dich, 'CASH-')) {
                $payment->ma_giao_dich = 'QR'.now()->format('Ymd').str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
            }
            $payment->save();

            return $this->formatQrData($order, $payment);
        });
    }

    /**
     * Check transaction status for customer (PAY-02).
     *
     * @return array<string, mixed>
     *
     * @throws HttpException
     */
    public function getPaymentStatus(int $accountId, int $orderId): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (! $order) {
            throw new HttpException(404, 'Order not found');
        }

        $payment = $order->payment;
        if (! $payment) {
            throw new HttpException(404, 'Payment record not found for this order');
        }

        return [
            'paymentId' => (int) $payment->ma_thanh_toan,
            'orderId' => (int) $order->ma_don_hang,
            'amount' => (float) $payment->so_tien,
            'paymentMethod' => $payment->phuong_thuc->name,
            'status' => $payment->trang_thai->name,
            'orderStatus' => $order->trang_thai->name,
            'transactionCode' => $payment->ma_giao_dich,
            'isPaid' => $payment->trang_thai === PaymentStatus::PAID,
        ];
    }

    /**
     * Retry payment when expired or failed (PAY-03).
     *
     * @return array<string, mixed>
     *
     * @throws HttpException
     */
    public function retryPayment(int $accountId, int $orderId): array
    {
        return DB::transaction(function () use ($accountId, $orderId): array {
            $order = $this->findPayableOrder($accountId, $orderId);
            $payment = $this->lockOrCreatePayment($order);

            if ($payment->trang_thai === PaymentStatus::PAID) {
                throw new HttpException(409, 'Order has already been paid');
            }

            $payment->trang_thai = PaymentStatus::UNPAID;
            $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
            $payment->ma_giao_dich = 'QR'.now()->format('Ymd').str_pad((string) $orderId, 4, '0', STR_PAD_LEFT).'-'.substr(uniqid(), -4);
            $payment->save();

            return $this->formatQrData($order, $payment, true);
        });
    }

    /**
     * Process an incoming payment gateway webhook (VietQR/SePay/PayOS).
     *
     * The raw body must be signed with HMAC-SHA256 using `services.payment_webhook.secret`.
     * Repeated callbacks for an already paid order are acknowledged without changes.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws HttpException
     */
    public function processWebhook(array $payload, string $rawBody, ?string $signature = null): Payment
    {
        $this->assertValidSignature($rawBody, $signature);

        $orderId = $this->resolveOrderId($payload);
        if (! $orderId) {
            throw new HttpException(400, 'Cannot identify order ID from webhook payload');
        }

        $paidAmount = $payload['amount'] ?? $payload['transferAmount'] ?? null;
        if (! is_numeric($paidAmount) || (float) $paidAmount <= 0) {
            throw new HttpException(422, 'Webhook payload must include a positive paid amount');
        }

        $result = DB::transaction(function () use ($orderId, $paidAmount, $payload): array {
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $order) {
                throw new HttpException(404, "Order #{$orderId} not found");
            }

            $payment = Payment::query()->where('ma_don_hang', $orderId)->lockForUpdate()->first();
            if (! $payment) {
                throw new HttpException(404, "Payment for order #{$orderId} not found");
            }

            if ($payment->trang_thai === PaymentStatus::PAID) {
                return ['payment' => $payment, 'error' => null];
            }

            if ((float) $paidAmount + 0.001 < (float) $payment->so_tien) {
                $payment->log_callback = json_encode([
                    'source' => 'WEBHOOK',
                    'payload' => $payload,
                    'error' => 'PAID_AMOUNT_TOO_LOW',
                    'receivedAt' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE);
                $payment->save();

                return ['payment' => $payment, 'error' => "Paid amount ({$paidAmount}) is less than order total ({$payment->so_tien})"];
            }

            $orderWasCancelled = $order->trang_thai === OrderStatus::CANCELLED;
            $transactionCode = $payload['transactionCode'] ?? $payload['id'] ?? null;

            $payment->trang_thai = PaymentStatus::PAID;
            $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
            if (! empty($transactionCode)) {
                $payment->ma_giao_dich = (string) $transactionCode;
            }
            $payment->log_callback = json_encode([
                'source' => 'WEBHOOK',
                'payload' => $payload,
                'requiresRefund' => $orderWasCancelled,
                'receivedAt' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE);
            $payment->save();

            if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION) {
                $order->update(['trang_thai' => OrderStatus::CONFIRMED]);
            }

            return ['payment' => $payment, 'error' => null];
        });

        if ($result['error']) {
            throw new HttpException(400, $result['error']);
        }

        return $result['payment'];
    }

    private function assertValidSignature(string $rawBody, ?string $signature): void
    {
        $secret = (string) config('services.payment_webhook.secret');

        if ($secret === '') {
            if (config('services.payment_webhook.allow_unsigned')) {
                return;
            }

            throw new HttpException(503, 'Payment webhook secret is not configured');
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (! $signature || ! hash_equals($expected, strtolower(trim($signature)))) {
            throw new HttpException(401, 'Invalid webhook signature');
        }
    }

    /** @param array<string, mixed> $payload */
    private function resolveOrderId(array $payload): ?int
    {
        if (! empty($payload['orderId']) && is_numeric($payload['orderId'])) {
            return (int) $payload['orderId'];
        }

        foreach (['content', 'description'] as $field) {
            if (! empty($payload[$field]) && preg_match('/DH(\d+)/i', (string) $payload[$field], $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function findPayableOrder(int $accountId, int $orderId): Order
    {
        $order = Order::query()
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            throw new HttpException(404, 'Order not found');
        }

        if ($order->trang_thai === OrderStatus::CANCELLED) {
            throw new HttpException(400, 'Order has already been cancelled');
        }

        if ($order->trang_thai === OrderStatus::COMPLETED) {
            throw new HttpException(409, 'Order has already been completed');
        }

        return $order;
    }

    private function lockOrCreatePayment(Order $order): Payment
    {
        $payment = Payment::query()->where('ma_don_hang', $order->ma_don_hang)->lockForUpdate()->first();

        return $payment ?? Payment::create([
            'so_tien' => $order->tong_tien,
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
            'trang_thai' => PaymentStatus::UNPAID,
            'ma_don_hang' => $order->ma_don_hang,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatQrData(Order $order, Payment $payment, bool $isRetry = false): array
    {
        $orderId = (int) $order->ma_don_hang;
        $amount = (float) $payment->so_tien;
        $qr = $this->vietQrService->build($amount, $this->vietQrService->orderTransferContent($orderId));
        $minutes = $this->vietQrService->expiresInMinutes();

        $initialExpiry = $order->ngay_dat_hang?->copy()->addMinutes($minutes);
        $expiresAt = ($isRetry || ! $initialExpiry || $initialExpiry->isPast())
            ? now()->addMinutes($minutes)
            : $initialExpiry;

        return [
            'paymentId' => (int) $payment->ma_thanh_toan,
            'orderId' => $orderId,
            'amount' => $amount,
            'paymentMethod' => $payment->phuong_thuc->name,
            'status' => $payment->trang_thai->name,
            'qrImageUrl' => $qr['qrImageUrl'],
            'transferContent' => $qr['transferContent'],
            'bankAccount' => [
                'bankBin' => $qr['bankBin'],
                'accountNo' => $qr['accountNo'],
                'accountName' => $qr['accountName'],
            ],
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }
}
