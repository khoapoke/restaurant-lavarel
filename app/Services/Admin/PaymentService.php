<?php

namespace App\Services\Admin;

use App\Enums\MoneySource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Shared\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Quản trị thanh toán trên sổ giao dịch (ADR-023): danh sách theo đơn (THANH_TOAN tổng hợp)
 * và theo khoản tiền (GIAO_DICH_TIEN), xác nhận chuyển khoản thủ công, áp tiền chưa khớp,
 * đối soát, ghi nhận đã hoàn tiền.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

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

        if (! empty($filters['attention'])) {
            $query->where(function ($nested): void {
                $nested->whereHas('order', fn ($order) => $order->where('trang_thai', OrderStatus::CANCELLED->value))
                    ->where('so_tien_da_thu', '>', 0)
                    ->orWhereColumn('so_tien_da_thu', '>', 'so_tien');
            });
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

    /** @param array<string, mixed> $filters */
    public function getTransactions(array $filters = []): LengthAwarePaginator
    {
        $query = MoneyTransaction::query()->with(['allocations', 'refunds.refundedBy.userProfile', 'recordedBy.userProfile', 'reviewedBy.userProfile']);

        if (! empty($filters['reconciliation'])) {
            $query->where('trang_thai_doi_soat', strtoupper((string) $filters['reconciliation']));
        }
        if (! empty($filters['orderId'])) {
            $orderId = (int) $filters['orderId'];
            $query->where(fn ($nested) => $nested->where('ma_don_hang_muc_tieu', $orderId)
                ->orWhereHas('allocations', fn ($allocation) => $allocation->where('ma_don_hang', $orderId)));
        }
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(fn ($nested) => $nested->where('ma_tham_chieu', 'like', "%{$keyword}%")->orWhere('noi_dung', 'like', "%{$keyword}%"));
        }

        return $query->orderByDesc('ma_giao_dich_tien')
            ->paginate(min(max((int) ($filters['perPage'] ?? 20), 1), 50));
    }

    /**
     * Ghi nhận chuyển khoản nhà hàng đã thấy trong ứng dụng ngân hàng (PAY-08). Dùng số tiền
     * thực nhận; nếu webhook đã ghi cùng mã giao dịch thì hợp nhất thay vì tạo thêm tiền.
     * Đơn đã hủy vẫn được ghi nhận (để hoàn), không áp vào đơn.
     */
    public function confirmTransfer(int $paymentId, Account $admin, string $transactionCode, ?string $note = null, ?float $amount = null, ?Carbon $receivedAt = null): Payment
    {
        return DB::transaction(function () use ($paymentId, $admin, $transactionCode, $note, $amount, $receivedAt): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->ma_don_hang);

            if ($payment->phuong_thuc !== PaymentMethod::BANK_TRANSFER_QR) {
                throw new HttpException(409, 'Cash payments are settled automatically at handoff');
            }

            // Đơn đã đủ tiền vẫn ghi nhận được khoản chuyển thật mới: sổ lưu tiền, áp 0 vào đơn và gắn cờ
            // DUPLICATE (chờ hoàn/phân bổ) — không tăng doanh thu bán hàng, không bỏ mất tiền.
            $outstanding = $this->ledger->outstanding($order);

            $recorded = $this->ledger->record([
                'source' => MoneySource::MANUAL,
                'method' => PaymentMethod::BANK_TRANSFER_QR,
                'amount' => $amount ?? ($outstanding > 0 ? $outstanding : (float) $order->tong_tien),
                'account' => config('services.vietqr.account_no'),
                'reference' => $transactionCode,
                'receivedAt' => $receivedAt ?? now(),
                'recordedBy' => $admin,
                'orderId' => $order->ma_don_hang,
                'payload' => ['note' => $note],
            ]);

            $applied = $this->ledger->applyToOrder($recorded['transaction'], $order, null, $admin);
            if (! $recorded['created'] && $applied <= 0) {
                $appliedOrders = array_keys($this->ledger->netAllocationsByOrder($recorded['transaction']));
                throw new HttpException(409, $appliedOrders === [] || in_array((int) $order->ma_don_hang, $appliedOrders, true)
                    ? 'This payment has already been confirmed'
                    : 'This bank transaction is already applied to another order.');
            }

            $order->refresh();
            if ($order->trang_thai === OrderStatus::PENDING_CONFIRMATION && $this->ledger->outstanding($order) <= $this->ledger->tolerance()) {
                $order->update(['trang_thai' => OrderStatus::CONFIRMED]);
            }

            $this->audit->record($admin, 'PAYMENT_TRANSFER_CONFIRMED', 'ORDER', (int) $order->ma_don_hang, $note, null, [
                'transactionId' => (int) $recorded['transaction']->ma_giao_dich_tien,
                'reference' => trim($transactionCode),
                'amount' => (float) $recorded['transaction']->so_tien,
                'applied' => $applied,
                'merged' => ! $recorded['created'],
            ]);

            $fresh = $payment->fresh()->load('order');
            // Kết quả của chính lần ghi nhận này: áp bao nhiêu, có bị gắn cờ chờ đối soát không (H).
            $fresh->setRelation('lastReceipt', $recorded['transaction']->fresh());
            $fresh->setAttribute('last_receipt_applied', $applied);

            return $fresh;
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
            ->map(function (Payment $payment): ?array {
                if (! $payment->order) {
                    return ['paymentId' => (int) $payment->ma_thanh_toan, 'orderId' => (int) $payment->ma_don_hang, 'reason' => 'ORDER_MISSING'];
                }

                $ledgerPaid = $this->ledger->paidAmount($payment->order);
                $reason = match (true) {
                    abs($ledgerPaid - (float) $payment->so_tien_da_thu) > 0.005 => 'SUMMARY_OUT_OF_SYNC',
                    $this->ledger->refundDue($payment->order) > 0 => 'REFUND_DUE',
                    round((float) $payment->so_tien, 2) !== round((float) $payment->order->tong_tien, 2) => 'AMOUNT_MISMATCH',
                    default => null,
                };

                return $reason ? [
                    'paymentId' => (int) $payment->ma_thanh_toan,
                    'orderId' => (int) $payment->ma_don_hang,
                    'paymentAmount' => (float) $payment->so_tien,
                    'orderAmount' => (float) $payment->order->tong_tien,
                    'amountPaid' => $ledgerPaid,
                    'refundDue' => $this->ledger->refundDue($payment->order),
                    'reason' => $reason,
                ] : null;
            })
            ->filter()
            ->values();

        $reviewCount = MoneyTransaction::query()->where('trang_thai_doi_soat', ReconciliationStatus::NEEDS_REVIEW->value)->count();

        return [
            'checkedCount' => $payments->count(),
            'matchedCount' => $payments->count() - $mismatches->count(),
            'mismatchCount' => $mismatches->count(),
            'mismatches' => $mismatches,
            'transactionsNeedingReview' => $reviewCount,
            'note' => 'Đối soát nội bộ giữa sổ giao dịch và đơn hàng; chưa đối chiếu sao kê ngân hàng.',
        ];
    }

    /**
     * Ghi nhận đã hoàn tiền theo đơn (toàn phần hoặc một phần).
     *
     * @param  array{amount: float, method: string, reference: string, reason: string, refundedAt?: ?Carbon}  $data
     */
    public function refund(int $paymentId, array $data, Account $admin): Payment
    {
        $payment = Payment::query()->findOrFail($paymentId);
        $order = Order::query()->findOrFail($payment->ma_don_hang);

        // Kiểm tra "đã thu" nằm trong refundOrder, sau bước nhận diện gửi lại.
        $this->ledger->refundOrder($order, $data, $admin);

        return $payment->fresh()->load('order');
    }

    /** @param array{amount: float, method: string, reference: string, reason: string, refundedAt?: ?Carbon} $data */
    public function refundTransaction(int $transactionId, array $data, Account $admin): MoneyTransaction
    {
        $transaction = MoneyTransaction::query()->findOrFail($transactionId);
        $this->ledger->refundTransaction($transaction, $data, $admin);

        return $this->loadTransaction($transactionId);
    }

    public function allocateTransaction(int $transactionId, int $orderId, Account $admin, ?float $amount, string $note): MoneyTransaction
    {
        $transaction = MoneyTransaction::query()->findOrFail($transactionId);
        $order = Order::query()->findOrFail($orderId);
        $this->ledger->allocateManually($transaction, $order, $admin, $amount, $note);

        return $this->loadTransaction($transactionId);
    }

    public function resolveTransaction(int $transactionId, Account $admin, string $note): MoneyTransaction
    {
        $this->ledger->resolve(MoneyTransaction::query()->findOrFail($transactionId), $admin, $note);

        return $this->loadTransaction($transactionId);
    }

    public function loadTransaction(int $transactionId): MoneyTransaction
    {
        return MoneyTransaction::query()
            ->with(['allocations', 'refunds.refundedBy.userProfile', 'recordedBy.userProfile', 'reviewedBy.userProfile'])
            ->findOrFail($transactionId);
    }

    public function getCallbackLogs(int $limit = 50): Collection
    {
        return MoneyTransaction::query()
            ->with('allocations')
            ->where('nguon', MoneySource::WEBHOOK->value)
            ->orderByDesc('ma_giao_dich_tien')
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
