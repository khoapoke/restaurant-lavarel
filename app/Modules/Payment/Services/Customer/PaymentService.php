<?php

namespace App\Modules\Payment\Services\Customer;

use App\Modules\Core\Enums\OrderStatus;
use App\Modules\Core\Enums\PaymentMethod;
use App\Modules\Core\Enums\PaymentStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\Payment;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    /**
     * Generate VietQR payment code for order (PAY-01).
     *
     * @param int $accountId
     * @param int $orderId
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function generateQr(int $accountId, int $orderId): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        if ($order->trang_thai === OrderStatus::CANCELLED) {
            throw new HttpException(400, 'Order has already been cancelled');
        }

        $payment = $order->payment;
        if (!$payment) {
            $payment = Payment::create([
                'so_tien' => $order->tong_tien,
                'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
                'trang_thai' => PaymentStatus::UNPAID,
                'ma_don_hang' => $order->ma_don_hang,
            ]);
        }

        // Kiểm tra nếu đã thanh toán trước đó
        if ($payment->trang_thai === PaymentStatus::PAID) {
            throw new HttpException(409, 'Order has already been paid');
        }

        // Đảm bảo phương thức là BANK_TRANSFER_QR và sinh mã giao dịch nếu chưa có
        $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
        if (empty($payment->ma_giao_dich)) {
            $payment->ma_giao_dich = 'QR' . date('Ymd') . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
        }
        $payment->save();

        return $this->formatQrData($order, $payment);
    }

    /**
     * Check transaction status for customer (PAY-02).
     *
     * @param int $accountId
     * @param int $orderId
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function getPaymentStatus(int $accountId, int $orderId): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        $payment = $order->payment;
        if (!$payment) {
            throw new HttpException(404, 'Payment record not found for this order');
        }

        $pMethod = $payment->phuong_thuc instanceof PaymentMethod
            ? $payment->phuong_thuc->name
            : (PaymentMethod::tryFrom($payment->phuong_thuc)?->name ?? (string) $payment->phuong_thuc);

        $pStatus = $payment->trang_thai instanceof PaymentStatus
            ? $payment->trang_thai->name
            : (PaymentStatus::tryFrom($payment->trang_thai)?->name ?? (string) $payment->trang_thai);

        return [
            'paymentId' => (int) $payment->paymentId,
            'orderId' => (int) $order->orderId,
            'amount' => (float) $payment->amount,
            'paymentMethod' => $pMethod,
            'status' => $pStatus,
            'transactionCode' => $payment->ma_giao_dich,
            'isPaid' => $payment->trang_thai === PaymentStatus::PAID,
        ];
    }

    /**
     * Retry payment when expired or failed (PAY-03).
     *
     * @param int $accountId
     * @param int $orderId
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function retryPayment(int $accountId, int $orderId): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        if ($order->trang_thai === OrderStatus::CANCELLED) {
            throw new HttpException(400, 'Order has already been cancelled');
        }

        $payment = $order->payment;
        if (!$payment) {
            $payment = Payment::create([
                'so_tien' => $order->tong_tien,
                'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
                'trang_thai' => PaymentStatus::UNPAID,
                'ma_don_hang' => $order->ma_don_hang,
            ]);
        }

        if ($payment->trang_thai === PaymentStatus::PAID) {
            throw new HttpException(409, 'Order has already been paid');
        }

        // Reset trạng thái thanh toán và tạo mã giao dịch mới
        $payment->trang_thai = PaymentStatus::UNPAID;
        $payment->phuong_thuc = PaymentMethod::BANK_TRANSFER_QR;
        $payment->ma_giao_dich = 'QR' . date('Ymd') . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT) . '-' . substr(uniqid(), -4);
        $payment->save();

        return $this->formatQrData($order, $payment, true);
    }

    /**
     * Process incoming payment webhook callback from gateway (VietQR/SePay/PayOS).
     *
     * @param array<string, mixed> $payload
     * @param string|null $signature
     * @return Payment|array<string, mixed>
     * @throws HttpException
     */
    public function processWebhook(array $payload, ?string $signature = null): Payment|array
    {
        // 1. Kiểm tra nếu là thanh toán tiền cọc đặt bàn (DB{id})
        $reservationId = null;
        if (!empty($payload['reservationId'])) {
            $reservationId = (int) $payload['reservationId'];
        } elseif (!empty($payload['content'])) {
            if (preg_match('/DB(\d+)/i', (string) $payload['content'], $matches)) {
                $reservationId = (int) $matches[1];
            }
        } elseif (!empty($payload['description'])) {
            if (preg_match('/DB(\d+)/i', (string) $payload['description'], $matches)) {
                $reservationId = (int) $matches[1];
            }
        }

        if ($reservationId) {
            $reservation = \App\Modules\Reservation\Models\Reservation::with('diningTable')->find($reservationId);
            if (!$reservation) {
                throw new HttpException(404, "Reservation #{$reservationId} not found");
            }

            $paidAmount = $payload['amount'] ?? $payload['transferAmount'] ?? null;
            if ($paidAmount !== null && (float) $paidAmount < (float) $reservation->tien_coc) {
                throw new HttpException(400, "Paid amount ({$paidAmount}) is less than required deposit ({$reservation->tien_coc})");
            }

            $reservation->da_thanh_toan_coc = true;
            $reservation->trang_thai = \App\Modules\Core\Enums\ReservationStatus::CONFIRMED;
            $reservation->save();

            $table = $reservation->diningTable;
            if ($table && $table->trang_thai === \App\Modules\Core\Enums\TableStatus::AVAILABLE) {
                $table->trang_thai = \App\Modules\Core\Enums\TableStatus::RESERVED;
                $table->save();
            }

            return [
                'reservationId' => (int) $reservation->ma_dat_ban,
                'status' => 'CONFIRMED',
                'depositAmount' => (float) $reservation->tien_coc,
                'isDepositPaid' => true,
            ];
        }

        // 2. Mặc định: Thanh toán đơn hàng (DH{id})
        $orderId = null;

        if (!empty($payload['orderId'])) {
            $orderId = (int) $payload['orderId'];
        } elseif (!empty($payload['content'])) {
            if (preg_match('/DH(\d+)/i', (string) $payload['content'], $matches)) {
                $orderId = (int) $matches[1];
            }
        } elseif (!empty($payload['description'])) {
            if (preg_match('/DH(\d+)/i', (string) $payload['description'], $matches)) {
                $orderId = (int) $matches[1];
            }
        }

        if (!$orderId) {
            throw new HttpException(400, 'Cannot identify order ID or reservation ID from webhook payload');
        }

        $order = Order::with('payment')->where('ma_don_hang', $orderId)->first();
        if (!$order) {
            throw new HttpException(404, "Order #{$orderId} not found");
        }

        $payment = $order->payment;
        if (!$payment) {
            throw new HttpException(404, "Payment for order #{$orderId} not found");
        }

        // Kiểm tra số tiền chuyển
        $paidAmount = $payload['amount'] ?? $payload['transferAmount'] ?? null;
        if ($paidAmount !== null && (float) $paidAmount < (float) $payment->so_tien) {
            $payment->log_callback = json_encode(['payload' => $payload, 'error' => 'Paid amount is less than expected']);
            $payment->save();
            throw new HttpException(400, "Paid amount ({$paidAmount}) is less than order total ({$payment->so_tien})");
        }

        // Cập nhật trạng thái thanh toán thành công
        $payment->trang_thai = PaymentStatus::PAID;
        if (!empty($payload['transactionCode']) || !empty($payload['id'])) {
            $payment->ma_giao_dich = (string) ($payload['transactionCode'] ?? $payload['id']);
        }
        $payment->log_callback = json_encode($payload);
        $payment->save();

        // Tự động chuyển đơn hàng sang CONFIRMED nếu đang PENDING_CONFIRMATION
        if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION) {
            $order->trang_thai = OrderStatus::CONFIRMED;
            $order->save();
        }

        return $payment;
    }

    /**
     * Format VietQR response data structure.
     *
     * @param Order $order
     * @param Payment $payment
     * @param bool $isRetry
     * @return array<string, mixed>
     */
    protected function formatQrData(Order $order, Payment $payment, bool $isRetry = false): array
    {
        $bankBin = env('VIETQR_BANK_BIN', '970436');
        $accountNo = env('VIETQR_ACCOUNT_NO', '1029384756');
        $template = env('VIETQR_TEMPLATE', 'compact2');

        $amount = (float) $payment->so_tien;
        $orderId = (int) $order->orderId;
        $transferContent = "DH{$orderId} THANH TOAN";

        $qrImageUrl = "https://img.vietqr.io/image/{$bankBin}-{$accountNo}-{$template}.png?amount="
            . ((int) $amount) . '&addInfo=' . urlencode($transferContent);

        $expiresAt = ($isRetry || !$order->ngay_dat_hang)
            ? now()->addMinutes(15)->toIso8601String()
            : $order->ngay_dat_hang->copy()->addMinutes(15)->toIso8601String();

        $pMethod = $payment->phuong_thuc instanceof PaymentMethod
            ? $payment->phuong_thuc->name
            : (PaymentMethod::tryFrom($payment->phuong_thuc)?->name ?? (string) $payment->phuong_thuc);

        $pStatus = $payment->trang_thai instanceof PaymentStatus
            ? $payment->trang_thai->name
            : (PaymentStatus::tryFrom($payment->trang_thai)?->name ?? (string) $payment->trang_thai);

        return [
            'paymentId' => (int) $payment->paymentId,
            'orderId' => $orderId,
            'amount' => $amount,
            'paymentMethod' => $pMethod,
            'status' => $pStatus,
            'qrImageUrl' => $qrImageUrl,
            'transferContent' => $transferContent,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Mock successful payment for customer order (dev/test without real webhook).
     *
     * @param int $accountId
     * @param int $orderId
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function mockPaymentSuccess(int $accountId, int $orderId): array
    {
        $order = Order::with('payment')
            ->where('ma_don_hang', $orderId)
            ->where('ma_tai_khoan', $accountId)
            ->first();

        if (!$order) {
            throw new HttpException(404, 'Order not found');
        }

        if ($order->trang_thai === OrderStatus::CANCELLED) {
            throw new HttpException(400, 'Order has already been cancelled');
        }

        $payment = $order->payment;
        if (!$payment) {
            $payment = Payment::create([
                'so_tien' => $order->tong_tien,
                'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
                'trang_thai' => PaymentStatus::UNPAID,
                'ma_don_hang' => $order->ma_don_hang,
            ]);
        }

        $payment->trang_thai = PaymentStatus::PAID;
        if (empty($payment->ma_giao_dich)) {
            $payment->ma_giao_dich = 'MOCK-' . strtoupper(uniqid());
        }
        $payment->log_callback = json_encode([
            'type' => 'customer_mock_payment',
            'time' => now()->toIso8601String(),
        ]);
        $payment->save();

        if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION) {
            $order->trang_thai = OrderStatus::CONFIRMED;
            $order->save();
        }

        return [
            'paymentId' => (int) $payment->paymentId,
            'orderId' => (int) $order->orderId,
            'amount' => (float) $payment->so_tien,
            'status' => 'PAID',
            'orderStatus' => 'CONFIRMED',
            'isPaid' => true,
        ];
    }
}

