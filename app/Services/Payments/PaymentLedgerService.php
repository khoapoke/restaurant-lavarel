<?php

namespace App\Services\Payments;

use App\Enums\AllocationType;
use App\Enums\CancellationMoneyDecision;
use App\Enums\InvoiceStatus;
use App\Enums\MoneyFlag;
use App\Enums\MoneySource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Services\Shared\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sổ giao dịch tiền (ADR-023).
 *
 * Nguyên tắc: mỗi khoản tiền thực nhận là một MoneyTransaction; tiền chỉ "trả" cho đơn qua
 * PaymentAllocation và không bao giờ vượt số còn lại của giao dịch; phần dư, chuyển lần hai,
 * tiền về cho đơn hủy/hóa đơn cũ được giữ nguyên trên giao dịch để đối soát hoặc hoàn.
 * THANH_TOAN chỉ là bản tổng hợp tính lại từ phân bổ và hoàn tiền.
 *
 * Mọi phương thức ghi dữ liệu phải được gọi bên trong DB::transaction của bên gọi hoặc tự
 * mở transaction; các dòng liên quan được khóa (lockForUpdate) trước khi tính số dư.
 */
class PaymentLedgerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Ghi nhận một khoản tiền. Trùng mã giao dịch (theo nhà cung cấp + tài khoản nhận) hoặc
     * trùng idempotency key thì trả lại giao dịch cũ, không tạo thêm tiền.
     *
     * @param  array{source: MoneySource, method: PaymentMethod, amount: float, provider?: ?string, account?: ?string,
     *     reference?: ?string, idempotencyKey?: ?string, receivedAt?: ?Carbon, recordedBy?: ?Account, content?: ?string,
     *     payload?: mixed, orderId?: ?int, invoiceId?: ?int, invoiceVersion?: ?int}  $data
     * @return array{transaction: MoneyTransaction, created: bool}
     */
    public function record(array $data): array
    {
        $provider = strtoupper(trim((string) ($data['provider'] ?? config('restaurant.payments.default_provider', 'VIETQR'))));
        $account = isset($data['account']) && $data['account'] !== '' ? trim((string) $data['account']) : null;
        $reference = isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null;
        $identity = null;
        if ($data['method'] === PaymentMethod::BANK_TRANSFER_QR) {
            // Định danh theo ngân hàng + tài khoản nhận thật, không theo nhãn nguồn (TransferIdentity).
            $identity = TransferIdentity::resolve($data['provider'] ?? null, $account, $reference);
            $uniqueKey = $identity['key'];
            $account = $identity['account'] ?? $account;
        } else {
            $uniqueKey = $reference !== null ? mb_strtoupper($provider.'|'.($account ?? '').'|'.$reference) : null;
        }
        $idempotencyKey = $data['idempotencyKey'] ?? null;

        // Đích đơn/hóa đơn chỉ là gợi ý từ nội dung chuyển khoản: mã không tồn tại không được làm
        // mất bản ghi tiền (khóa ngoại) — tiền vẫn lưu, giao dịch được gắn cờ chờ đối soát.
        $orderId = isset($data['orderId']) && Order::query()->whereKey($data['orderId'])->exists() ? (int) $data['orderId'] : null;
        $invoiceId = isset($data['invoiceId']) && Invoice::query()->whereKey($data['invoiceId'])->exists() ? (int) $data['invoiceId'] : null;

        $existing = $this->findExisting($uniqueKey, $idempotencyKey);
        if ($existing) {
            return ['transaction' => $existing, 'created' => false];
        }

        try {
            $transaction = MoneyTransaction::create([
                'nguon' => $data['source'],
                'nha_cung_cap' => $provider,
                'tai_khoan_nhan' => $account,
                'ma_tham_chieu' => $reference,
                'khoa_duy_nhat' => $uniqueKey,
                'idempotency_key' => $idempotencyKey,
                'phuong_thuc' => $data['method'],
                'so_tien' => round((float) $data['amount'], 2),
                'so_tien_da_phan_bo' => 0,
                'so_tien_da_hoan' => 0,
                'noi_dung' => isset($data['content']) ? mb_substr((string) $data['content'], 0, 255) : null,
                'ma_don_hang_muc_tieu' => $orderId,
                'ma_hoa_don_muc_tieu' => $invoiceId,
                'phien_ban_hoa_don' => $invoiceId ? ($data['invoiceVersion'] ?? null) : null,
                'trang_thai_doi_soat' => ReconciliationStatus::MATCHED,
                'nhan_luc' => $data['receivedAt'] ?? now(),
                'ghi_nhan_luc' => now(),
                'ma_nguoi_ghi_nhan' => $data['recordedBy']?->ma_tai_khoan ?? null,
                'du_lieu_goc' => isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($uniqueKey, $idempotencyKey);
            if ($existing) {
                return ['transaction' => $existing, 'created' => false];
            }

            throw $exception;
        }

        if ($reference === null && $data['method'] === PaymentMethod::BANK_TRANSFER_QR) {
            $this->flag($transaction, MoneyFlag::NO_REFERENCE);
        }
        if ($identity !== null && $uniqueKey !== null) {
            $this->reviewAmbiguousIdentity($transaction, $identity);
        }

        return ['transaction' => $transaction, 'created' => true];
    }

    /**
     * Thông tin ngân hàng mâu thuẫn, hoặc cùng mã tham chiếu đã có ở ngân hàng/tài khoản khác mà một bên
     * không nêu đủ ngân hàng + tài khoản: không gộp và không bỏ tiền — giữ khoản riêng, chuyển đối soát.
     *
     * @param  array{bank: string, account: ?string, key: ?string, explicit: bool, conflict: ?string}  $identity
     */
    private function reviewAmbiguousIdentity(MoneyTransaction $transaction, array $identity): void
    {
        $notes = array_filter([$identity['conflict']]);
        $sameReference = MoneyTransaction::query()
            ->whereKeyNot($transaction->ma_giao_dich_tien)
            ->where('phuong_thuc', PaymentMethod::BANK_TRANSFER_QR->value)
            ->whereRaw('UPPER(TRIM(ma_tham_chieu)) = ?', [mb_strtoupper(trim((string) $transaction->ma_tham_chieu))])
            ->where('khoa_duy_nhat', '!=', $identity['key'])
            ->get(['ma_giao_dich_tien', 'nha_cung_cap', 'tai_khoan_nhan']);
        $ambiguous = $sameReference->filter(fn (MoneyTransaction $other) => ! $identity['explicit']
            || ! TransferIdentity::isExplicit($other->nha_cung_cap, $other->tai_khoan_nhan));
        if ($ambiguous->isNotEmpty()) {
            $notes[] = 'Cùng mã tham chiếu với giao dịch #'.$ambiguous->pluck('ma_giao_dich_tien')->implode(', #')
                .' ở ngân hàng/tài khoản khác nhưng thiếu thông tin ngân hàng hoặc tài khoản để phân biệt — kiểm tra có phải cùng một khoản tiền.';
        }
        if ($notes === []) {
            return;
        }

        $transaction->forceFill([
            'trang_thai_doi_soat' => ReconciliationStatus::NEEDS_REVIEW,
            'ghi_chu_doi_soat' => mb_substr(implode(' ', $notes), 0, 500),
        ])->save();
    }

    /**
     * Áp tiền của giao dịch vào đơn, tối đa số còn thiếu của đơn và số chưa phân bổ của giao dịch.
     * Phần không áp được được đánh dấu để đối soát (dư, chuyển thêm, đơn đã hủy).
     *
     * @return float số tiền đã áp
     */
    public function applyToOrder(MoneyTransaction $transaction, Order $order, ?Invoice $invoice, ?Account $actor, ?float $limit = null): float
    {
        $transaction = MoneyTransaction::query()->lockForUpdate()->findOrFail($transaction->ma_giao_dich_tien);
        $order = Order::query()->lockForUpdate()->findOrFail($order->ma_don_hang);

        $available = $transaction->unallocatedAmount();
        if ($available <= 0) {
            return 0.0;
        }

        if ($order->trang_thai === OrderStatus::CANCELLED) {
            $this->flag($transaction, MoneyFlag::ORDER_CANCELLED);

            return 0.0;
        }

        $outstanding = $this->outstanding($order);
        if ($outstanding <= $this->tolerance()) {
            $this->flag($transaction, MoneyFlag::DUPLICATE);

            return 0.0;
        }

        $amount = round(min($available, $outstanding, $limit ?? PHP_FLOAT_MAX), 2);

        PaymentAllocation::create([
            'ma_giao_dich_tien' => $transaction->ma_giao_dich_tien,
            'ma_don_hang' => $order->ma_don_hang,
            'ma_hoa_don' => $invoice?->ma_hoa_don,
            'phien_ban_hoa_don' => $invoice?->phien_ban,
            'so_tien' => $amount,
            'loai' => AllocationType::APPLY,
            'ma_nguoi_thuc_hien' => $actor?->ma_tai_khoan,
            'tao_luc' => now(),
        ]);
        $transaction->forceFill([
            'so_tien_da_phan_bo' => round((float) $transaction->so_tien_da_phan_bo + $amount, 2),
            'ma_don_hang_muc_tieu' => $transaction->ma_don_hang_muc_tieu ?? $order->ma_don_hang,
        ])->save();

        if ($available - $amount > $this->tolerance()) {
            $this->flag($transaction, MoneyFlag::OVERPAID);
        } elseif ($amount + $this->tolerance() < $outstanding) {
            $transaction->forceFill(['canh_bao' => MoneyFlag::UNDERPAID])->save();
        }

        $this->syncOrderPayment($order->fresh());
        if ($invoice) {
            $this->settleInvoiceIfPaid($invoice, $order->fresh());
        }

        return $amount;
    }

    public function flag(MoneyTransaction $transaction, MoneyFlag $flag): void
    {
        $transaction->forceFill([
            'canh_bao' => $flag,
            'trang_thai_doi_soat' => ReconciliationStatus::NEEDS_REVIEW,
        ])->save();
    }

    public function paidAmount(Order $order): float
    {
        return round((float) PaymentAllocation::query()->where('ma_don_hang', $order->ma_don_hang)->sum('so_tien'), 2);
    }

    public function refundedAmount(Order $order): float
    {
        return round((float) Refund::query()->where('ma_don_hang', $order->ma_don_hang)->sum('so_tien'), 2);
    }

    /** Số còn thiếu của đơn = khoản phải thu − đã thu (đơn hủy không còn thiếu). */
    public function outstanding(Order $order): float
    {
        if ($order->trang_thai === OrderStatus::CANCELLED) {
            return 0.0;
        }

        return round(max(0, $order->payableAmount() - $this->paidAmount($order)), 2);
    }

    /** Tiền đã áp vào đơn nhưng nhà hàng phải hoàn (đơn hủy, hoặc tổng đơn giảm sau khi đã thu). */
    public function refundDue(Order $order): float
    {
        $paid = $this->paidAmount($order);
        if ($order->trang_thai === OrderStatus::CANCELLED) {
            return $order->quyet_dinh_tien_huy === CancellationMoneyDecision::RETAINED->value ? 0.0 : round(max(0, $paid), 2);
        }

        return round(max(0, $paid - $order->payableAmount()), 2);
    }

    /** Tính lại bản tổng hợp THANH_TOAN của đơn từ phân bổ và hoàn tiền. */
    public function syncOrderPayment(Order $order): Payment
    {
        $payment = Payment::query()->where('ma_don_hang', $order->ma_don_hang)->lockForUpdate()->first();
        $paid = $this->paidAmount($order);
        $refunded = $this->refundedAmount($order);
        $total = round((float) $order->tong_tien, 2);
        $compensated = (float) ($order->so_tien_giam_tru ?? 0) > 0;

        $status = match (true) {
            $paid <= 0 && $refunded > 0 => PaymentStatus::REFUNDED,
            $paid <= 0 => PaymentStatus::UNPAID,
            $refunded > 0 && $order->trang_thai === OrderStatus::CANCELLED => PaymentStatus::PARTIALLY_REFUNDED,
            // Đủ khoản phải thu sau khi bồi hoàn một phần: đã trả xong, có hoàn một phần.
            $compensated && $paid + $this->tolerance() >= $order->payableAmount() => PaymentStatus::PARTIALLY_REFUNDED,
            $paid + $this->tolerance() >= $order->payableAmount() => PaymentStatus::PAID,
            default => PaymentStatus::PARTIALLY_PAID,
        };

        $latestTransaction = MoneyTransaction::query()
            ->whereHas('allocations', fn ($query) => $query->where('ma_don_hang', $order->ma_don_hang)->where('loai', AllocationType::APPLY->value))
            ->orderByDesc('ma_giao_dich_tien')
            ->first();
        $latestReference = $latestTransaction?->ma_tham_chieu;

        $attributes = [
            'so_tien' => $total,
            'so_tien_da_thu' => $paid,
            'so_tien_da_hoan' => $refunded,
            'trang_thai' => $status,
            'cap_nhat_luc' => now(),
        ];
        if ($latestReference) {
            $attributes['ma_giao_dich'] = $latestReference;
        }
        if ($latestTransaction) {
            $attributes['phuong_thuc'] = $latestTransaction->phuong_thuc;
        }

        if (! $payment) {
            return Payment::create([
                ...$attributes,
                'ma_don_hang' => $order->ma_don_hang,
                'phuong_thuc' => PaymentMethod::CASH,
                'thanh_toan_luc' => $status === PaymentStatus::PAID ? now() : null,
            ]);
        }

        if ($status === PaymentStatus::PAID && ! $payment->thanh_toan_luc) {
            $attributes['thanh_toan_luc'] = now();
        }
        $payment->forceFill($attributes)->save();

        return $payment;
    }

    /** Hóa đơn chuyển PAID khi đơn của phiên không còn thiếu tiền. */
    public function settleInvoiceIfPaid(Invoice $invoice, Order $order): void
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->ma_hoa_don);
        if ($invoice->trang_thai !== InvoiceStatus::OPEN || $this->outstanding($order) > $this->tolerance()) {
            return;
        }

        $invoice->update(['trang_thai' => InvoiceStatus::PAID, 'thanh_toan_luc' => now()]);
        InvoiceVersion::query()
            ->where('ma_hoa_don', $invoice->ma_hoa_don)
            ->where('phien_ban', $invoice->phien_ban)
            ->update(['trang_thai' => InvoiceStatus::PAID->value]);
        Payment::query()->where('ma_don_hang', $order->ma_don_hang)->update(['ma_hoa_don' => $invoice->ma_hoa_don]);
    }

    /**
     * Ghi nhận đã hoàn tiền (ADMIN, có chứng từ). Lấy trước từ phần chưa phân bổ của giao dịch,
     * sau đó từ phần đã áp vào đơn ($order ưu tiên). Không hoàn vượt số tiền đã nhận trừ đã hoàn.
     *
     * Loại hoàn (`type`): REFUND (mặc định) chỉ hoàn phần nhà hàng đang nợ — phần chưa phân bổ hoặc
     * phần đã thu vượt khoản phải thu. Hoàn vượt mức đó trên đơn còn hiệu lực phải ghi là COMPENSATION:
     * khoản phải thu của đơn giảm tương ứng (giảm trừ sau thanh toán), không phát sinh "còn thiếu" giả.
     *
     * @param  array{amount: float, method: string, reference: string, reason: string, refundedAt?: ?Carbon, type?: ?string, requestKey?: ?string}  $data
     * @return Collection<int, Refund> các dòng chi tiết của yêu cầu hoàn
     */
    public function refund(MoneyTransaction $transaction, array $data, Account $admin, ?Order $order = null, bool $preferAllocated = false): Collection
    {
        return DB::transaction(function () use ($transaction, $data, $admin, $order, $preferAllocated): Collection {
            $transaction = MoneyTransaction::query()->lockForUpdate()->findOrFail($transaction->ma_giao_dich_tien);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new HttpException(422, 'Refund amount must be greater than zero');
            }
            if ($amount - $transaction->refundableAmount() > $this->tolerance()) {
                throw new HttpException(409, 'Refund exceeds the money received minus earlier refunds');
            }

            $allocatedByOrder = $this->netAllocationsByOrder($transaction);
            if ($order && isset($allocatedByOrder[$order->ma_don_hang])) {
                $allocatedByOrder = [$order->ma_don_hang => $allocatedByOrder[$order->ma_don_hang]] + $allocatedByOrder;
            }

            $unallocated = $transaction->unallocatedAmount();
            $allocatedTotal = array_sum($allocatedByOrder);
            $fromAllocated = $preferAllocated
                ? min($amount, $allocatedTotal)
                : max(0, $amount - $unallocated);
            $fromAllocated = round(min($fromAllocated, $allocatedTotal), 2);
            if ($amount - $fromAllocated - $unallocated > $this->tolerance()) {
                throw new HttpException(409, 'Refund exceeds the money received minus earlier refunds');
            }

            // Phần lấy từ tiền đã áp vào đơn còn hiệu lực vượt quá số nhà hàng đang nợ là bồi hoàn.
            $type = ($data['type'] ?? null) === Refund::TYPE_COMPENSATION ? Refund::TYPE_COMPENSATION : Refund::TYPE_REFUND;
            $plan = [];
            $remainingPlan = $fromAllocated;
            foreach ($allocatedByOrder as $orderId => $net) {
                if ($remainingPlan <= 0) {
                    break;
                }
                $take = round(min($remainingPlan, $net), 2);
                $target = Order::query()->lockForUpdate()->findOrFail($orderId);
                $compensation = $target->trang_thai === OrderStatus::CANCELLED ? 0.0 : round(max(0, $take - $this->refundDue($target)), 2);
                if ($compensation > $this->tolerance() && $type !== Refund::TYPE_COMPENSATION) {
                    throw new HttpException(422, 'This refund would leave the order unpaid. Record it as compensation for the customer or reduce the amount.');
                }
                $plan[] = [$target, $take, $compensation];
                $remainingPlan = round($remainingPlan - $take, 2);
            }
            // Một yêu cầu hoàn = một hoặc nhiều dòng HOAN_TIEN cùng khóa yêu cầu: mỗi đơn bị rút tiền có dòng
            // riêng đúng số rút từ đơn đó, phần lấy từ tiền chưa phân bổ là một dòng riêng (gắn đơn đích của
            // giao dịch). Tổng các dòng = số tiền hoàn của yêu cầu.
            $common = [
                'khoa_yeu_cau' => $data['requestKey'] ?? null,
                'ma_giao_dich_tien' => $transaction->ma_giao_dich_tien,
                'phuong_thuc' => $data['method'],
                'ma_chung_tu' => $data['reference'],
                'ly_do' => $data['reason'],
                'ma_nguoi_thuc_hien' => $admin->ma_tai_khoan,
                'hoan_luc' => $data['refundedAt'] ?? now(),
                'ghi_nhan_luc' => now(),
            ];
            $rows = collect();
            $fromUnallocated = round($amount - $fromAllocated, 2);
            if ($fromUnallocated > $this->tolerance()) {
                $rows->push(Refund::create([
                    ...$common,
                    'loai_hoan' => Refund::TYPE_REFUND,
                    'ma_don_hang' => $order?->ma_don_hang ?? $transaction->ma_don_hang_muc_tieu,
                    'so_tien' => $fromUnallocated,
                    'so_tien_tu_phan_bo' => 0,
                ]));
            }

            $touchedOrders = [];
            foreach ($plan as [$target, $take, $compensation]) {
                $row = Refund::create([
                    ...$common,
                    'loai_hoan' => $compensation > $this->tolerance() ? Refund::TYPE_COMPENSATION : Refund::TYPE_REFUND,
                    'ma_don_hang' => $target->ma_don_hang,
                    'so_tien' => $take,
                    'so_tien_tu_phan_bo' => $take,
                ]);
                $rows->push($row);
                PaymentAllocation::create([
                    'ma_giao_dich_tien' => $transaction->ma_giao_dich_tien,
                    'ma_don_hang' => $target->ma_don_hang,
                    'so_tien' => -1 * $take,
                    'loai' => AllocationType::REFUND_REVERSAL,
                    'ma_hoan_tien' => $row->ma_hoan_tien,
                    'ma_nguoi_thuc_hien' => $admin->ma_tai_khoan,
                    'tao_luc' => now(),
                ]);
                if ($compensation > $this->tolerance()) {
                    $target->forceFill(['so_tien_giam_tru' => round((float) $target->so_tien_giam_tru + $compensation, 2)])->save();
                }
                $touchedOrders[] = (int) $target->ma_don_hang;
            }

            $transaction->forceFill([
                'so_tien_da_hoan' => round((float) $transaction->so_tien_da_hoan + $amount, 2),
                'so_tien_da_phan_bo' => round((float) $transaction->so_tien_da_phan_bo - $fromAllocated, 2),
            ])->save();
            if ($transaction->trang_thai_doi_soat === ReconciliationStatus::NEEDS_REVIEW && $transaction->unallocatedAmount() <= $this->tolerance()) {
                $transaction->forceFill(['trang_thai_doi_soat' => ReconciliationStatus::RESOLVED, 'doi_soat_luc' => now(), 'ma_nguoi_doi_soat' => $admin->ma_tai_khoan])->save();
            }

            $rowOrders = $rows->pluck('ma_don_hang')->filter()->map(fn ($id) => (int) $id)->all();
            foreach (array_unique(array_merge($touchedOrders, $rowOrders)) as $orderId) {
                $this->syncOrderPayment(Order::query()->findOrFail($orderId));
            }

            $this->audit->record($admin, 'PAYMENT_REFUND_RECORDED', 'MONEY_TRANSACTION', (int) $transaction->ma_giao_dich_tien, $data['reason'], null, [
                'refundIds' => $rows->map(fn (Refund $row) => (int) $row->ma_hoan_tien)->all(),
                'amount' => $amount,
                'fromAllocated' => $fromAllocated,
                'fromUnallocated' => $fromUnallocated,
                'method' => $data['method'],
                'reference' => $data['reference'],
                'byOrder' => $rows->filter(fn (Refund $row) => $row->ma_don_hang !== null)
                    ->groupBy('ma_don_hang')->map(fn (Collection $group) => round((float) $group->sum('so_tien'), 2))->all(),
                'type' => $rows->contains(fn (Refund $row) => $row->loai_hoan === Refund::TYPE_COMPENSATION) ? Refund::TYPE_COMPENSATION : Refund::TYPE_REFUND,
                'compensation' => round((float) collect($plan)->sum(fn (array $step) => $step[2]), 2),
            ]);

            return $rows->map(fn (Refund $row) => $row->fresh())->values();
        });
    }

    /**
     * Hoàn tiền theo đơn: rút từ các giao dịch đã áp vào đơn, mới nhất trước.
     *
     * @param  array{amount: float, method: string, reference: string, reason: string, refundedAt?: ?Carbon}  $data
     * @return Collection<int, Refund>
     */
    public function refundOrder(Order $order, array $data, Account $admin): Collection
    {
        return DB::transaction(function () use ($order, $data, $admin): Collection {
            $order = Order::query()->lockForUpdate()->findOrFail($order->ma_don_hang);
            $amount = round((float) $data['amount'], 2);

            // Gửi lại cùng yêu cầu/chứng từ: trả lại các dòng đã ghi, không hoàn thêm.
            $requestKey = $this->refundRequestKey($data, 'O'.$order->ma_don_hang);
            $replay = $this->refundReplay(Refund::query()->where('ma_don_hang', $order->ma_don_hang), $requestKey, $data);
            if ($replay !== null) {
                return $replay;
            }
            if ($this->paidAmount($order) <= 0) {
                throw new HttpException(400, 'Only paid transactions can be refunded');
            }
            $data['requestKey'] = $requestKey;
            if ($amount - $this->paidAmount($order) > $this->tolerance()) {
                throw new HttpException(409, 'Refund exceeds the money applied to this order minus earlier refunds');
            }

            $transactions = MoneyTransaction::query()
                ->whereHas('allocations', fn ($query) => $query->where('ma_don_hang', $order->ma_don_hang))
                ->orderByDesc('ma_giao_dich_tien')
                ->get();

            $refunds = collect();
            $remaining = $amount;
            foreach ($transactions as $transaction) {
                $net = $this->netAllocationsByOrder($transaction)[$order->ma_don_hang] ?? 0;
                if ($remaining <= 0 || $net <= 0) {
                    continue;
                }
                $take = round(min($remaining, $net), 2);
                $refunds = $refunds->merge($this->refund($transaction, [...$data, 'amount' => $take], $admin, $order, true));
                $remaining = round($remaining - $take, 2);
            }

            return $refunds;
        });
    }

    /**
     * Hoàn theo giao dịch (chống gửi lặp như hoàn theo đơn).
     *
     * @param  array{amount: float, method: string, reference: string, reason: string, refundedAt?: ?Carbon, type?: ?string, idempotencyKey?: ?string}  $data
     */
    public function refundTransaction(MoneyTransaction $transaction, array $data, Account $admin): Collection
    {
        return DB::transaction(function () use ($transaction, $data, $admin): Collection {
            $locked = MoneyTransaction::query()->lockForUpdate()->findOrFail($transaction->ma_giao_dich_tien);
            $requestKey = $this->refundRequestKey($data, 'T'.$locked->ma_giao_dich_tien);
            $replay = $this->refundReplay(Refund::query()->where('ma_giao_dich_tien', $locked->ma_giao_dich_tien), $requestKey, $data);
            if ($replay !== null) {
                return $replay;
            }

            return $this->refund($locked, [...$data, 'requestKey' => $requestKey], $admin);
        });
    }

    /** Khóa yêu cầu hoàn: Idempotency-Key của client, hoặc chứng từ hoàn trong phạm vi đơn/giao dịch. */
    private function refundRequestKey(array $data, string $scope): string
    {
        if (! empty($data['idempotencyKey'])) {
            return 'KEY:'.mb_substr((string) $data['idempotencyKey'], 0, 120);
        }

        return 'VOUCHER:'.$scope.'|'.mb_strtoupper(trim((string) $data['reference']));
    }

    /**
     * Đã có yêu cầu hoàn cùng khóa hoặc cùng chứng từ trong phạm vi: cùng số tiền + phương thức là gửi lại
     * (trả về dòng cũ); khác nội dung là dùng lại chứng từ cho khoản khác → 409.
     *
     * @return Collection<int, Refund>|null
     */
    private function refundReplay($scopeQuery, string $requestKey, array $data): ?Collection
    {
        $voucher = mb_strtoupper(trim((string) $data['reference']));
        $existing = (clone $scopeQuery)->where('khoa_yeu_cau', $requestKey)->get();
        if ($existing->isEmpty()) {
            $existing = (clone $scopeQuery)->whereRaw('UPPER(TRIM(ma_chung_tu)) = ?', [$voucher])->get();
        }
        if ($existing->isEmpty()) {
            return null;
        }

        $sameAmount = abs((float) $existing->sum('so_tien') - round((float) $data['amount'], 2)) <= max(0.005, $this->tolerance());
        $sameMethod = $existing->every(fn (Refund $refund) => $refund->phuong_thuc === $data['method']);
        if (! $sameAmount || ! $sameMethod) {
            throw new HttpException(409, 'This refund voucher or request has already been recorded with different details.');
        }

        return $existing->map(fn (Refund $refund) => $refund->setAttribute('was_replayed', true))->values();
    }

    /** ADMIN áp một khoản tiền chưa khớp vào đơn (ví dụ QR hóa đơn cũ → hóa đơn mới). */
    public function allocateManually(MoneyTransaction $transaction, Order $order, Account $admin, ?float $amount, string $note): float
    {
        return DB::transaction(function () use ($transaction, $order, $admin, $amount, $note): float {
            $invoice = Invoice::query()->where('ma_don_hang', $order->ma_don_hang)->first();
            $applied = $this->applyToOrder($transaction, $order, $invoice?->trang_thai === InvoiceStatus::OPEN ? $invoice : null, $admin, $amount);
            if ($applied <= 0) {
                throw new HttpException(409, 'Nothing could be applied: the order has no outstanding amount or the transaction has no unallocated money');
            }

            $fresh = MoneyTransaction::query()->findOrFail($transaction->ma_giao_dich_tien);
            if ($fresh->unallocatedAmount() <= $this->tolerance()) {
                $fresh->forceFill(['trang_thai_doi_soat' => ReconciliationStatus::RESOLVED, 'ghi_chu_doi_soat' => $note, 'doi_soat_luc' => now(), 'ma_nguoi_doi_soat' => $admin->ma_tai_khoan])->save();
            }

            $this->audit->record($admin, 'PAYMENT_ALLOCATED_MANUALLY', 'MONEY_TRANSACTION', (int) $transaction->ma_giao_dich_tien, $note, null, [
                'orderId' => (int) $order->ma_don_hang,
                'amount' => $applied,
            ]);

            return $applied;
        });
    }

    /** Đánh dấu đã đối soát (ví dụ đã liên hệ khách, chờ hoàn ngoài hệ thống). */
    public function resolve(MoneyTransaction $transaction, Account $admin, string $note): MoneyTransaction
    {
        return DB::transaction(function () use ($transaction, $admin, $note): MoneyTransaction {
            $locked = MoneyTransaction::query()->lockForUpdate()->findOrFail($transaction->ma_giao_dich_tien);
            if ($locked->unallocatedAmount() > $this->tolerance()) {
                throw new HttpException(409, 'Allocate or refund the remaining money before marking this transaction as reconciled');
            }
            $locked->forceFill([
                'trang_thai_doi_soat' => ReconciliationStatus::RESOLVED,
                'ghi_chu_doi_soat' => $note,
                'doi_soat_luc' => now(),
                'ma_nguoi_doi_soat' => $admin->ma_tai_khoan,
            ])->save();
            $this->audit->record($admin, 'PAYMENT_RECONCILED', 'MONEY_TRANSACTION', (int) $locked->ma_giao_dich_tien, $note);

            return $locked;
        });
    }

    /** @return array<int, float> mã đơn => số tiền ròng của giao dịch đang áp vào đơn đó */
    public function netAllocationsByOrder(MoneyTransaction $transaction): array
    {
        return PaymentAllocation::query()
            ->where('ma_giao_dich_tien', $transaction->ma_giao_dich_tien)
            ->get()
            ->groupBy('ma_don_hang')
            ->map(fn (Collection $rows) => round((float) $rows->sum('so_tien'), 2))
            ->filter(fn (float $net) => $net > 0)
            ->sortKeysDesc()
            ->all();
    }

    public function tolerance(): float
    {
        return (float) config('restaurant.payments.tolerance', 0);
    }

    private function findExisting(?string $uniqueKey, ?string $idempotencyKey): ?MoneyTransaction
    {
        if ($idempotencyKey) {
            $byKey = MoneyTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($byKey) {
                return $byKey;
            }
        }

        return $uniqueKey ? MoneyTransaction::query()->where('khoa_duy_nhat', $uniqueKey)->first() : null;
    }
}
