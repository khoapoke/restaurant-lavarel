<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    /** @param array<string, mixed> $filters */
    public function getPayments(array $filters = []): LengthAwarePaginator
    {
        $query = Payment::query()->with('order');

        if (! empty($filters['status'])) {
            $query->where('trang_thai', $this->statusValue((string) $filters['status']));
        }

        if (! empty($filters['method'])) {
            $query->where('phuong_thuc', $this->methodValue((string) $filters['method']));
        }

        if (! empty($filters['keyword'])) {
            $keyword = ltrim(trim((string) $filters['keyword']), '#');
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('ma_don_hang', $keyword)
                    ->orWhere('ma_thanh_toan', $keyword)
                    ->orWhere('ma_giao_dich', 'like', "%{$keyword}%");
            });
        }

        return $query->orderByDesc('ma_thanh_toan')
            ->paginate(min(max((int) ($filters['perPage'] ?? 15), 1), 50));
    }

    /**
     * Record a bank transfer the restaurant verified in its banking app (PAY-08).
     */
    public function confirmTransfer(int $paymentId, Account $admin, string $transactionCode, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($paymentId, $admin, $transactionCode, $note): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->ma_don_hang);

            if ($payment->trang_thai === PaymentStatus::PAID) {
                throw new HttpException(409, 'This payment has already been confirmed');
            }

            if ($payment->trang_thai === PaymentStatus::REFUNDED) {
                throw new HttpException(409, 'A refunded payment cannot be confirmed again');
            }

            if ($payment->phuong_thuc !== PaymentMethod::BANK_TRANSFER_QR) {
                throw new HttpException(409, 'Cash payments are settled automatically at handoff');
            }

            if ($order->trang_thai === OrderStatus::CANCELLED) {
                throw new HttpException(409, 'Cannot confirm a transfer for a cancelled order');
            }

            $payment->update([
                'trang_thai' => PaymentStatus::PAID,
                'ma_giao_dich' => trim($transactionCode),
                'log_callback' => json_encode([
                    'source' => 'MANUAL_CONFIRMATION',
                    'confirmedBy' => (int) $admin->ma_tai_khoan,
                    'note' => $note,
                    'confirmedAt' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION) {
                $order->update(['trang_thai' => OrderStatus::CONFIRMED]);
            }

            return $payment->fresh()->load('order');
        });
    }

    /** @param list<int> $paymentIds */
    public function reconcile(array $paymentIds = []): array
    {
        $payments = Payment::query()
            ->with('order')
            ->when($paymentIds !== [], fn ($query) => $query->whereIn('ma_thanh_toan', $paymentIds))
            ->get();

        $mismatches = $payments
            ->filter(fn (Payment $payment): bool => ! $payment->order || round((float) $payment->so_tien, 2) !== round((float) $payment->order->tong_tien, 2))
            ->map(fn (Payment $payment): array => [
                'paymentId' => (int) $payment->ma_thanh_toan,
                'orderId' => (int) $payment->ma_don_hang,
                'paymentAmount' => (float) $payment->so_tien,
                'orderAmount' => $payment->order ? (float) $payment->order->tong_tien : null,
                'reason' => $payment->order ? 'AMOUNT_MISMATCH' : 'ORDER_MISSING',
            ])->values();

        return [
            'checkedCount' => $payments->count(),
            'matchedCount' => $payments->count() - $mismatches->count(),
            'mismatchCount' => $mismatches->count(),
            'mismatches' => $mismatches,
        ];
    }

    public function refund(int $paymentId, string $reason): Payment
    {
        return DB::transaction(function () use ($paymentId, $reason): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->trang_thai !== PaymentStatus::PAID) {
                throw new HttpException(400, 'Only paid transactions can be refunded');
            }

            $payment->update([
                'trang_thai' => PaymentStatus::REFUNDED,
                'ly_do_hoan_tien' => $reason,
            ]);

            return $payment->fresh()->load('order');
        });
    }

    public function getCallbackLogs(int $limit = 50): Collection
    {
        return Payment::query()
            ->with('order')
            ->whereNotNull('log_callback')
            ->orderByDesc('ma_thanh_toan')
            ->limit(min(max($limit, 1), 100))
            ->get();
    }

    private function statusValue(string $input): string
    {
        foreach (PaymentStatus::cases() as $status) {
            if ($status->name === $input || $status->value === $input) {
                return $status->value;
            }
        }

        throw new HttpException(422, 'Unknown payment status');
    }

    private function methodValue(string $input): string
    {
        foreach (PaymentMethod::cases() as $method) {
            if ($method->name === $input || $method->value === $input) {
                return $method->value;
            }
        }

        throw new HttpException(422, 'Unknown payment method');
    }
}
