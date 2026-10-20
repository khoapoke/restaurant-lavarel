<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    /**
     * Get paginated payment transactions with search and filters (PAY-04).
     *
     * @param array<string, mixed> $params
     * @return LengthAwarePaginator
     */
    public function getPayments(array $params = []): LengthAwarePaginator
    {
        $query = Payment::with(['order.account.userProfile']);

        // Filter by payment status (English enum name or Vietnamese value)
        if (!empty($params['status'])) {
            $statusInput = trim((string) $params['status']);
            $matchedStatus = null;
            foreach (PaymentStatus::cases() as $case) {
                if ($case->name === $statusInput || $case->value === $statusInput) {
                    $matchedStatus = $case;
                    break;
                }
            }
            if ($matchedStatus) {
                $query->where('trang_thai', $matchedStatus->value);
            }
        }

        // Filter by payment method (English enum name or Vietnamese value)
        $methodInput = $params['paymentMethod'] ?? $params['method'] ?? null;
        if (!empty($methodInput)) {
            $methodStr = trim((string) $methodInput);
            $matchedMethod = null;
            foreach (PaymentMethod::cases() as $case) {
                if ($case->name === $methodStr || $case->value === $methodStr) {
                    $matchedMethod = $case;
                    break;
                }
            }
            if ($matchedMethod) {
                $query->where('phuong_thuc', $matchedMethod->value);
            }
        }

        // Filter by order ID
        if (!empty($params['orderId'])) {
            $query->where('ma_don_hang', (int) $params['orderId']);
        }

        // Search by transaction code, order ID, or customer name/phone/username
        if (!empty($params['search'])) {
            $search = trim((string) $params['search']);
            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->orWhere('ma_don_hang', (int) $search)
                      ->orWhere('ma_thanh_toan', (int) $search);
                }
                $q->orWhere('ma_giao_dich', 'LIKE', "%{$search}%")
                  ->orWhereHas('order.account', function ($sub) use ($search) {
                      $sub->where('ten_dang_nhap', 'LIKE', "%{$search}%")
                          ->orWhereHas('userProfile', function ($pSub) use ($search) {
                              $pSub->where('ho_ten', 'LIKE', "%{$search}%")
                                   ->orWhere('so_dien_thoai', 'LIKE', "%{$search}%");
                          });
                  });
            });
        }

        $query->orderByDesc('ma_thanh_toan');

        $perPage = (int) ($params['perPage'] ?? 10);
        return $query->paginate($perPage);
    }

    /**
     * Reconcile payment amount with order total (PAY-05).
     *
     * @param int|null $orderId
     * @param int|null $paymentId
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function reconcilePayments(?int $orderId = null, ?int $paymentId = null): array
    {
        // Reconcile single payment / order
        if ($paymentId || $orderId) {
            $query = Payment::with('order');
            if ($paymentId) {
                $query->where('ma_thanh_toan', $paymentId);
            } else {
                $query->where('ma_don_hang', $orderId);
            }

            $payment = $query->first();
            if (!$payment) {
                throw new HttpException(404, 'Payment not found');
            }

            $order = $payment->order;
            $paymentAmount = (float) $payment->so_tien;
            $orderTotal = (float) ($order?->tong_tien ?? 0);
            $difference = round($paymentAmount - $orderTotal, 2);
            $isMatched = abs($difference) < 0.01;

            $pStatus = $payment->trang_thai instanceof PaymentStatus
                ? $payment->trang_thai->name
                : (PaymentStatus::tryFrom($payment->trang_thai)?->name ?? (string) $payment->trang_thai);

            $oStatus = $order?->trang_thai instanceof OrderStatus
                ? $order->trang_thai->name
                : (OrderStatus::tryFrom($order?->trang_thai)?->name ?? (string) $order?->trang_thai);

            $issue = null;
            if (!$isMatched) {
                $issue = "Payment amount ({$paymentAmount}) does not match order total ({$orderTotal})";
            } elseif ($pStatus === 'UNPAID' && $oStatus === 'COMPLETED') {
                $issue = "Order is COMPLETED but payment is still UNPAID";
            }

            return [
                'paymentId' => (int) $payment->paymentId,
                'orderId' => (int) $payment->orderId,
                'paymentAmount' => $paymentAmount,
                'orderTotal' => $orderTotal,
                'difference' => $difference,
                'isMatched' => $isMatched,
                'paymentStatus' => $pStatus,
                'orderStatus' => $oStatus,
                'issue' => $issue,
            ];
        }

        // Batch reconciliation across all payments
        $payments = Payment::with('order')->get();
        $totalChecked = 0;
        $matchedCount = 0;
        $discrepancyCount = 0;
        $discrepancies = [];

        foreach ($payments as $payment) {
            $totalChecked++;
            $order = $payment->order;
            $paymentAmount = (float) $payment->so_tien;
            $orderTotal = (float) ($order?->tong_tien ?? 0);
            $diff = round($paymentAmount - $orderTotal, 2);
            $matched = abs($diff) < 0.01;

            $pStatus = $payment->trang_thai instanceof PaymentStatus
                ? $payment->trang_thai->name
                : (PaymentStatus::tryFrom($payment->trang_thai)?->name ?? (string) $payment->trang_thai);

            $oStatus = $order?->trang_thai instanceof OrderStatus
                ? $order->trang_thai->name
                : (OrderStatus::tryFrom($order?->trang_thai)?->name ?? (string) $order?->trang_thai);

            $issue = null;
            if (!$matched) {
                $issue = "Payment amount ({$paymentAmount}) does not match order total ({$orderTotal})";
            } elseif ($pStatus === 'UNPAID' && $oStatus === 'COMPLETED') {
                $issue = "Order is COMPLETED but payment is still UNPAID";
            }

            if ($matched && !$issue) {
                $matchedCount++;
            } else {
                $discrepancyCount++;
                $discrepancies[] = [
                    'paymentId' => (int) $payment->paymentId,
                    'orderId' => (int) $payment->orderId,
                    'paymentAmount' => $paymentAmount,
                    'orderTotal' => $orderTotal,
                    'difference' => $diff,
                    'isMatched' => $matched,
                    'paymentStatus' => $pStatus,
                    'orderStatus' => $oStatus,
                    'issue' => $issue,
                ];
            }
        }

        return [
            'totalChecked' => $totalChecked,
            'matchedCount' => $matchedCount,
            'discrepancyCount' => $discrepancyCount,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Record refund for a paid transaction (PAY-06).
     *
     * @param int $paymentId
     * @param string $refundReason
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function refundPayment(int $paymentId, string $refundReason): array
    {
        $payment = Payment::with('order')
            ->where('ma_thanh_toan', $paymentId)
            ->first();

        if (!$payment) {
            throw new HttpException(404, 'Payment not found');
        }

        $pStatus = $payment->trang_thai instanceof PaymentStatus
            ? $payment->trang_thai
            : PaymentStatus::tryFrom($payment->trang_thai);

        // Quy tắc: Chỉ có giao dịch đã thanh toán (PAID) mới được hoàn tiền
        if ($pStatus !== PaymentStatus::PAID) {
            throw new HttpException(400, 'Only PAID transactions can be refunded');
        }

        $payment->trang_thai = PaymentStatus::REFUNDED;
        $payment->ly_do_hoan_tien = $refundReason;
        $payment->save();

        // Đồng bộ trạng thái đơn hàng: Hủy đơn nếu đơn chưa bị hủy
        if ($payment->order && $payment->order->trang_thai !== OrderStatus::CANCELLED) {
            $payment->order->trang_thai = OrderStatus::CANCELLED;
            $payment->order->save();
        }

        return [
            'paymentId' => (int) $payment->paymentId,
            'orderId' => (int) $payment->orderId,
            'amount' => (float) $payment->amount,
            'status' => 'REFUNDED',
            'refundReason' => $payment->refundReason,
        ];
    }

    /**
     * Retrieve callback logs for inspection and debugging (PAY-07).
     *
     * @param array<string, mixed> $params
     * @return LengthAwarePaginator
     */
    public function getCallbackLogs(array $params = []): LengthAwarePaginator
    {
        $query = Payment::whereNotNull('log_callback');

        if (!empty($params['onlyErrors']) && filter_var($params['onlyErrors'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where(function ($q) {
                $q->where('log_callback', 'LIKE', '%error%')
                  ->orWhere('trang_thai', PaymentStatus::FAILED->value);
            });
        }

        if (!empty($params['orderId'])) {
            $query->where('ma_don_hang', (int) $params['orderId']);
        }

        $query->orderByDesc('ma_thanh_toan');

        $perPage = (int) ($params['perPage'] ?? 10);
        return $query->paginate($perPage);
    }
}
