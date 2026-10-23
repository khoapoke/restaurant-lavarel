<?php

namespace App\Services\Customer;

use App\Enums\InvoiceStatus;
use App\Enums\MoneyFlag;
use App\Enums\MoneySource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Models\Invoice;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Dining\ServiceRequestService;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Payments\VietQrService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function __construct(
        private readonly VietQrService $vietQrService,
        private readonly PaymentLedgerService $ledger,
        private readonly ServiceRequestService $serviceRequests,
    ) {}

    /**
     * Generate VietQR payment code for order (PAY-01).
     *
     * Phương thức thanh toán bị khóa từ khi đơn được xác nhận (ADR-025): đơn tiền mặt đã
     * xác nhận không thể bị khách âm thầm chuyển sang QR.
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
            $this->assertMethodCanBecomeQr($order, $payment);

            $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
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
            'amountPaid' => (float) $payment->so_tien_da_thu,
            'outstanding' => $this->ledger->outstanding($order),
            'paymentMethod' => $payment->phuong_thuc->name,
            'status' => $payment->trang_thai->name,
            'orderStatus' => $order->trang_thai->name,
            'transactionCode' => $payment->ma_giao_dich,
            'isPaid' => $payment->trang_thai === PaymentStatus::PAID,
        ];
    }

    /**
     * Retry payment when expired or failed (PAY-03). Một mã QR mới cho đúng số còn thiếu.
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
            $this->assertMethodCanBecomeQr($order, $payment);

            $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
            $payment->save();

            return $this->formatQrData($order, $payment, true);
        });
    }

    /**
     * Webhook từ cổng thanh toán (ADR-023).
     *
     * Mọi callback hợp lệ chữ ký đều được ghi vào sổ giao dịch, kể cả khi không áp được vào
     * đơn/hóa đơn (dư, chuyển lần hai, đơn hủy, QR hóa đơn cũ, không rõ đích, thiếu mã giao
     * dịch). Callback trùng cùng mã giao dịch không tạo thêm tiền. Phản hồi 200 cho mọi
     * khoản đã ghi nhận để cổng không gửi lại; trạng thái đối soát nằm trong `data`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{transaction: MoneyTransaction, duplicateCallback: bool, applied: float}
     *
     * @throws HttpException
     */
    public function processWebhook(array $payload, string $rawBody, ?string $signature = null): array
    {
        $this->assertValidSignature($rawBody, $signature);

        $paidAmount = $payload['amount'] ?? $payload['transferAmount'] ?? null;
        if (! is_numeric($paidAmount) || (float) $paidAmount <= 0) {
            throw new HttpException(422, 'Webhook payload must include a positive paid amount');
        }

        [$invoiceId, $invoiceVersion] = $this->resolveInvoiceTarget($payload);
        $orderId = $invoiceId ? null : $this->resolveOrderId($payload);
        $content = (string) ($payload['content'] ?? $payload['description'] ?? '');

        return DB::transaction(function () use ($payload, $paidAmount, $invoiceId, $invoiceVersion, $orderId, $content): array {
            $recorded = $this->ledger->record([
                'source' => MoneySource::WEBHOOK,
                'method' => PaymentMethod::BANK_TRANSFER_QR,
                'amount' => (float) $paidAmount,
                'provider' => $payload['gateway'] ?? $payload['provider'] ?? null,
                // Không mặc định tài khoản cấu hình ở đây: TransferIdentity tự quyết định theo ngân hàng nhận.
                'account' => $payload['accountNumber'] ?? $payload['account'] ?? null,
                'reference' => $payload['transactionCode'] ?? $payload['referenceCode'] ?? $payload['id'] ?? null,
                'receivedAt' => $this->receivedAt($payload),
                'content' => $content,
                'payload' => $payload,
                'orderId' => $orderId,
                'invoiceId' => $invoiceId,
                'invoiceVersion' => $invoiceVersion,
            ]);
            $transaction = $recorded['transaction'];

            if (! $recorded['created']) {
                return ['transaction' => $transaction, 'duplicateCallback' => true, 'applied' => 0.0];
            }

            if ($transaction->ma_tham_chieu === null) {
                // Không có mã giao dịch ổn định: không suy đoán là đã thu, chờ đối soát.
                return ['transaction' => $transaction, 'duplicateCallback' => false, 'applied' => 0.0];
            }

            $applied = $invoiceId
                ? $this->applyToInvoice($transaction, $invoiceId, $invoiceVersion)
                : $this->applyToOnlineOrder($transaction, $orderId);

            return ['transaction' => $transaction->fresh(), 'duplicateCallback' => false, 'applied' => $applied];
        });
    }

    private function applyToOnlineOrder(MoneyTransaction $transaction, ?int $orderId): float
    {
        $order = $orderId ? Order::query()->lockForUpdate()->find($orderId) : null;
        if (! $order) {
            $this->ledger->flag($transaction, MoneyFlag::UNKNOWN_TARGET);

            return 0.0;
        }

        $applied = $this->ledger->applyToOrder($transaction, $order, null, null);
        $order->refresh();

        if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION && $this->ledger->outstanding($order) <= $this->ledger->tolerance()) {
            $order->update(['trang_thai' => OrderStatus::CONFIRMED]);
        }

        return $applied;
    }

    private function applyToInvoice(MoneyTransaction $transaction, int $invoiceId, ?int $version): float
    {
        $invoice = Invoice::query()->with('session')->lockForUpdate()->find($invoiceId);
        if (! $invoice) {
            $this->ledger->flag($transaction, MoneyFlag::UNKNOWN_TARGET);

            return 0.0;
        }

        $order = Order::query()->findOrFail($invoice->ma_don_hang);
        $transaction->forceFill(['ma_don_hang_muc_tieu' => $order->ma_don_hang])->save();

        $versionMatches = $version === null ? (int) $invoice->phien_ban === 1 : (int) $invoice->phien_ban === $version;
        if ($invoice->trang_thai === InvoiceStatus::PAID || in_array($invoice->session?->trang_thai, [PosSessionStatus::CLOSED, PosSessionStatus::CANCELLED], true)) {
            $this->ledger->flag($transaction, $invoice->trang_thai === InvoiceStatus::PAID ? MoneyFlag::DUPLICATE : MoneyFlag::SESSION_CLOSED);

            return 0.0;
        }

        if ($invoice->trang_thai !== InvoiceStatus::OPEN || ! $versionMatches) {
            $this->ledger->flag($transaction, MoneyFlag::INVOICE_STALE);

            return 0.0;
        }

        $applied = $this->ledger->applyToOrder($transaction, $order, $invoice, null);
        if ($invoice->fresh()->trang_thai === InvoiceStatus::PAID) {
            $this->serviceRequests->completePaymentRequests((int) $invoice->ma_phien, null);
        }

        return $applied;
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
    private function receivedAt(array $payload): Carbon
    {
        foreach (['transactionDate', 'transferredAt', 'paidAt'] as $field) {
            if (! empty($payload[$field])) {
                try {
                    return Carbon::parse((string) $payload[$field]);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return now();
    }

    /**
     * Nội dung hóa đơn tại bàn: "HD{id}V{phiên bản} THANH TOAN". Nội dung cũ "HD{id}" (không
     * phiên bản) chỉ khớp phiên bản 1.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveInvoiceTarget(array $payload): array
    {
        if (! empty($payload['invoiceId']) && is_numeric($payload['invoiceId'])) {
            return [(int) $payload['invoiceId'], isset($payload['invoiceVersion']) && is_numeric($payload['invoiceVersion']) ? (int) $payload['invoiceVersion'] : null];
        }

        foreach (['content', 'description'] as $field) {
            if (! empty($payload[$field]) && preg_match('/(?<![A-Z])HD(\d+)(?:V(\d+))?/i', (string) $payload[$field], $matches)) {
                return [(int) $matches[1], isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null];
            }
        }

        return [null, null];
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

    private function assertMethodCanBecomeQr(Order $order, Payment $payment): void
    {
        if ($payment->trang_thai === PaymentStatus::PAID) {
            throw new HttpException(409, 'Order has already been paid');
        }

        if ($payment->phuong_thuc === PaymentMethod::CASH && $order->trang_thai !== OrderStatus::PENDING_CONFIRMATION) {
            throw new HttpException(409, 'Payment method is locked once the order is confirmed');
        }
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
        $amount = $this->ledger->outstanding($order);
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
            'orderTotal' => (float) $order->tong_tien,
            'amountPaid' => (float) $payment->so_tien_da_thu,
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
