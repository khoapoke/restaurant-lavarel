<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
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
            $query->where('phuong_thuc', (string) $filters['method']);
        }

        return $query->orderByDesc('ma_thanh_toan')
            ->paginate(min(max((int) ($filters['perPage'] ?? 15), 1), 50));
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
}
