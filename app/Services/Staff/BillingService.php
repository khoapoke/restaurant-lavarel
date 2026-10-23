<?php

namespace App\Services\Staff;

use App\Enums\FulfillmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ItemVoidReason;
use App\Enums\KitchenItemStatus;
use App\Enums\MoneySource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\SessionEndType;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Dining\ServiceRequestService;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Hóa đơn và thu tiền tại bàn (ADR-019, ADR-023, ADR-027).
 *
 * - Hóa đơn có phiên bản: hủy (VOID) một phiên bản rồi lập lại tạo phiên bản mới, không
 *   dùng lại phiên bản đã hủy; nội dung VietQR chứa phiên bản.
 * - Mọi khoản thu đi qua sổ giao dịch; tiền mặt có thể thu nhiều lần (thu bù), chuyển khoản
 *   ghi đúng số tiền thực nhận, phần dư được giữ để đối soát.
 * - Đóng phiên khi hóa đơn đã đủ tiền, hoặc ADMIN kết thúc thất thu (khách bỏ đi).
 */
class BillingService
{
    public function __construct(
        private readonly ServiceRequestService $serviceRequests,
        private readonly PaymentLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function createInvoice(ServiceSession $session, ?int $staffId = null): Invoice
    {
        return DB::transaction(function () use ($session, $staffId) {
            $lockedSession = ServiceSession::lockForUpdate()->findOrFail($session->ma_phien);
            if (! in_array($lockedSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
                throw new HttpException(409, 'This service session cannot be invoiced.');
            }

            $existing = Invoice::where('ma_phien', $lockedSession->ma_phien)->lockForUpdate()->first();
            if ($existing && $existing->trang_thai === InvoiceStatus::OPEN) {
                return $existing->load('payment');
            }
            if ($existing && $existing->trang_thai !== InvoiceStatus::VOID) {
                return $existing->load('payment');
            }

            $order = Order::with('items')->where('ma_phien', $lockedSession->ma_phien)
                ->where('trang_thai', '!=', OrderStatus::CANCELLED->value)
                ->first();
            // Món đã hủy/từ chối vẫn thuộc hóa đơn nếu còn số phần tính tiền (vd. khách đổi ý sau khi bếp nấu).
            $billableItems = $order?->items->reject(fn (OrderItem $item) => in_array($item->trang_thai_bep, [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true)
                && (int) ($item->so_luong_tinh_tien ?? 0) === 0);
            if (! $order || ! $billableItems || $billableItems->isEmpty()) {
                throw new HttpException(409, 'Cannot create an invoice for an empty session.');
            }
            if ($billableItems->contains(fn ($item) => $item->trang_thai_bep === KitchenItemStatus::DRAFT)) {
                throw new HttpException(409, 'Send all draft items to the kitchen before creating the invoice.');
            }

            $amounts = [
                'tam_tinh' => $order->tong_tien,
                'giam_gia' => 0,
                'tong_thanh_toan' => $order->tong_tien,
                'trang_thai' => InvoiceStatus::OPEN,
                'tao_luc' => now(),
            ];

            if ($existing) {
                $existing->update([...$amounts, 'phien_ban' => $existing->phien_ban + 1]);
                $invoice = $existing;
            } else {
                $invoice = Invoice::create([
                    'ma_phien' => $lockedSession->ma_phien,
                    'ma_don_hang' => $order->ma_don_hang,
                    'phien_ban' => 1,
                    ...$amounts,
                ]);
            }
            $this->recordVersion($invoice);

            $lockedSession->update([
                'trang_thai' => PosSessionStatus::AWAITING_PAYMENT,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);
            $this->serviceRequests->acknowledgePaymentRequests((int) $lockedSession->ma_phien, $staffId);
            // Khoản đã thu trước (ví dụ thu một phần) vẫn được tính cho phiên bản mới.
            $this->ledger->syncOrderPayment($order);
            $this->ledger->settleInvoiceIfPaid($invoice, $order);

            return $invoice->fresh('payment');
        });
    }

    /**
     * Void an unpaid invoice so the table can order more dishes in the same session.
     */
    public function reopen(ServiceSession $session, ?Account $actor = null, ?string $reason = null): ServiceSession
    {
        return DB::transaction(function () use ($session, $actor, $reason): ServiceSession {
            $lockedSession = ServiceSession::lockForUpdate()->findOrFail($session->ma_phien);
            if ($lockedSession->trang_thai !== PosSessionStatus::AWAITING_PAYMENT) {
                throw new HttpException(409, 'Only a session awaiting payment can be reopened for more orders.');
            }

            $invoice = Invoice::where('ma_phien', $lockedSession->ma_phien)->lockForUpdate()->first();
            if (! $invoice || $invoice->trang_thai !== InvoiceStatus::OPEN) {
                throw new HttpException(409, 'A paid invoice cannot be reopened.');
            }

            $this->voidCurrentVersion($invoice, $actor, $reason ?? 'Mở lại để gọi thêm món');
            $lockedSession->update([
                'trang_thai' => PosSessionStatus::OPEN,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);

            return $this->loadSession($lockedSession->ma_phien);
        });
    }

    /**
     * Tổng đơn đổi khi hóa đơn đang mở (hủy/từ chối món): hủy phiên bản hiện tại và phát
     * hành phiên bản mới với tổng mới. Gọi bên trong transaction của thao tác gây thay đổi.
     */
    public function reissueOpenInvoice(Order $order, ?Account $actor, string $reason): void
    {
        $invoice = Invoice::query()->where('ma_don_hang', $order->ma_don_hang)->lockForUpdate()->first();
        if (! $invoice || $invoice->trang_thai !== InvoiceStatus::OPEN) {
            return;
        }

        $order->refresh();
        if (abs((float) $invoice->tong_thanh_toan - (float) $order->tong_tien) < 0.005) {
            return;
        }

        $this->voidCurrentVersion($invoice, $actor, $reason);
        $invoice->update([
            'tam_tinh' => $order->tong_tien,
            'tong_thanh_toan' => $order->tong_tien,
            'trang_thai' => InvoiceStatus::OPEN,
            'tao_luc' => now(),
            'phien_ban' => $invoice->phien_ban + 1,
        ]);
        $this->recordVersion($invoice->fresh());
        $this->ledger->syncOrderPayment($order);
        $this->ledger->settleInvoiceIfPaid($invoice, $order);
    }

    /**
     * Ghi nhận một khoản thu tại POS.
     *
     * - CASH: số tiền thực thu (không gồm tiền thối), 0 < số tiền ≤ số còn thiếu; thu nhiều lần được.
     * - BANK_TRANSFER_QR: mã giao dịch ngân hàng bắt buộc, số tiền thực nhận; mã đã được webhook
     *   ghi trước đó thì hợp nhất, không tạo thêm tiền.
     *
     * @param  array{method: string, amount: float|string, transactionCode?: ?string, idempotencyKey: string, cashTendered?: float|string|null}  $data
     */
    public function pay(Invoice $invoice, array $data, ?int $staffId = null): Payment
    {
        $staff = $staffId ? Account::query()->find($staffId) : null;
        // Gửi lại sau khi mất phản hồi: cùng khóa + cùng nội dung → trả kết quả cũ, không thu thêm.
        // Cùng khóa nhưng khác số tiền/phương thức/hóa đơn là lỗi phía client → 409, không đoán.
        $existing = MoneyTransaction::query()->where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            $sameRequest = abs((float) $existing->so_tien - round((float) $data['amount'], 2)) <= 0.005
                && $existing->phuong_thuc === ($data['method'] === 'BANK_TRANSFER_QR' ? PaymentMethod::BANK_TRANSFER_QR : PaymentMethod::CASH)
                && (int) $existing->ma_hoa_don_muc_tieu === (int) $invoice->ma_hoa_don;
            if (! $sameRequest) {
                throw new HttpException(409, 'Idempotency key was already used with a different request.');
            }

            return Payment::query()->where('ma_don_hang', $invoice->ma_don_hang)->firstOrFail();
        }

        return DB::transaction(function () use ($invoice, $data, $staff) {
            $lockedInvoice = Invoice::lockForUpdate()->findOrFail($invoice->ma_hoa_don);
            $order = Order::query()->lockForUpdate()->findOrFail($lockedInvoice->ma_don_hang);
            if ($lockedInvoice->trang_thai === InvoiceStatus::PAID) {
                throw new HttpException(409, 'This invoice has already been paid in full.');
            }
            if ($lockedInvoice->trang_thai !== InvoiceStatus::OPEN) {
                throw new HttpException(409, 'This invoice has been voided. Create a new invoice before taking payment.');
            }

            $outstanding = $this->ledger->outstanding($order);
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new HttpException(422, 'Payment amount must be greater than zero.');
            }

            $method = $data['method'] === 'BANK_TRANSFER_QR' ? PaymentMethod::BANK_TRANSFER_QR : PaymentMethod::CASH;

            if ($method === PaymentMethod::CASH) {
                if ($amount - $outstanding > $this->ledger->tolerance()) {
                    throw new HttpException(409, "Cash collected cannot exceed the outstanding amount of {$outstanding}. Give the change back to the guest.");
                }

                $recorded = $this->ledger->record([
                    'source' => MoneySource::POS,
                    'method' => PaymentMethod::CASH,
                    'provider' => 'CASH_DRAWER',
                    'amount' => $amount,
                    'idempotencyKey' => $data['idempotencyKey'],
                    'recordedBy' => $staff,
                    'orderId' => $order->ma_don_hang,
                    'invoiceId' => $lockedInvoice->ma_hoa_don,
                    'invoiceVersion' => $lockedInvoice->phien_ban,
                    'payload' => ['cashTendered' => isset($data['cashTendered']) ? (float) $data['cashTendered'] : null],
                ]);
            } else {
                $reference = trim((string) ($data['transactionCode'] ?? ''));
                if (mb_strlen($reference) < (int) config('restaurant.payments.min_reference_length', 6)) {
                    throw new HttpException(422, 'Enter the bank transaction code (at least 6 characters) shown in the banking app.');
                }

                $recorded = $this->ledger->record([
                    'source' => MoneySource::POS,
                    'method' => PaymentMethod::BANK_TRANSFER_QR,
                    'amount' => $amount,
                    'account' => config('services.vietqr.account_no'),
                    'reference' => $reference,
                    'idempotencyKey' => $data['idempotencyKey'],
                    'recordedBy' => $staff,
                    'orderId' => $order->ma_don_hang,
                    'invoiceId' => $lockedInvoice->ma_hoa_don,
                    'invoiceVersion' => $lockedInvoice->phien_ban,
                ]);

                if (! $recorded['created']) {
                    $this->assertTransferCanBeMerged($recorded['transaction'], $order);
                }
            }

            $applied = $this->ledger->applyToOrder($recorded['transaction'], $order, $lockedInvoice, $staff);
            if ($applied <= 0 && $recorded['created'] === false) {
                throw new HttpException(409, 'This bank transaction has already been recorded and applied.');
            }

            $this->audit->record($staff, 'POS_PAYMENT_RECORDED', 'INVOICE', (int) $lockedInvoice->ma_hoa_don, null, null, [
                'transactionId' => (int) $recorded['transaction']->ma_giao_dich_tien,
                'method' => $method->name,
                'amount' => $amount,
                'applied' => $applied,
                'invoiceVersion' => (int) $lockedInvoice->phien_ban,
            ]);

            if ($lockedInvoice->fresh()->trang_thai === InvoiceStatus::PAID) {
                $this->serviceRequests->completePaymentRequests((int) $lockedInvoice->ma_phien, $staff?->ma_tai_khoan);
            }

            return Payment::query()->where('ma_don_hang', $order->ma_don_hang)->firstOrFail();
        });
    }

    public function close(ServiceSession $session, int $expectedVersion, ?Account $actor = null): ServiceSession
    {
        return DB::transaction(function () use ($session, $expectedVersion, $actor) {
            $lockedSession = ServiceSession::with(['invoice.payment', 'orders.items', 'tables'])
                ->lockForUpdate()
                ->findOrFail($session->ma_phien);

            if ($lockedSession->phien_ban !== $expectedVersion) {
                throw new HttpException(409, 'Session version conflict. Refresh data and try again.');
            }
            if (! $lockedSession->invoice || $lockedSession->invoice->trang_thai !== InvoiceStatus::PAID) {
                throw new HttpException(409, 'The invoice must be fully paid before closing the session.');
            }

            $order = $lockedSession->orders->first(fn (Order $sessionOrder) => $sessionOrder->trang_thai !== OrderStatus::CANCELLED);
            if (! $order) {
                throw new HttpException(409, 'The service session has no order.');
            }
            if ($this->ledger->outstanding($order) > $this->ledger->tolerance()) {
                throw new HttpException(409, 'The invoice must be fully paid before closing the session.');
            }
            $unfinished = $order->items->contains(fn ($item) => ! in_array($item->trang_thai_bep, [KitchenItemStatus::READY, KitchenItemStatus::SERVED, KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true));
            if ($unfinished) {
                throw new HttpException(409, 'All kitchen items must be READY or SERVED before closing.');
            }

            $this->finishSession($lockedSession, $order, SessionEndType::NORMAL);
            $this->audit->record($actor, 'SESSION_CLOSED', 'SESSION', (int) $lockedSession->ma_phien);

            return $this->loadSession($lockedSession->ma_phien);
        });
    }

    /**
     * Kết thúc phiên không phát sinh tiền: mọi món đã gửi bếp đều bị hủy/từ chối và không còn phần
     * tính tiền (ví dụ bếp hết toàn bộ nguyên liệu). Không cần hóa đơn; giữ nguyên lịch sử món; đơn
     * được ghi hủy với lý do; bàn chuyển chờ vệ sinh vì khách đã ngồi. Không áp dụng khi còn món
     * nháp, món còn tính tiền hoặc đã có tiền nhận cho phiên (phải hoàn trước).
     */
    public function finishWithoutCharge(ServiceSession $session, Account $actor, string $reason): ServiceSession
    {
        return DB::transaction(function () use ($session, $actor, $reason): ServiceSession {
            $lockedSession = ServiceSession::with(['invoice', 'orders.items', 'tables'])->lockForUpdate()->findOrFail($session->ma_phien);

            if ($lockedSession->trang_thai !== PosSessionStatus::OPEN) {
                throw new HttpException(409, 'Only an open session can be finished without charge.');
            }
            if ($lockedSession->invoice && $lockedSession->invoice->trang_thai !== InvoiceStatus::VOID) {
                throw new HttpException(409, 'This session has an invoice. Settle or reopen it instead.');
            }

            $items = $lockedSession->orders->flatMap(fn (Order $order) => $order->items);
            $withdrawn = [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED];
            if ($items->contains(fn (OrderItem $item) => $item->trang_thai_bep === KitchenItemStatus::DRAFT)) {
                throw new HttpException(409, 'Send or remove the draft items first.');
            }
            if ($items->isEmpty() || ! $items->contains(fn (OrderItem $item) => in_array($item->trang_thai_bep, $withdrawn, true))) {
                throw new HttpException(409, 'Nothing reached the kitchen. Cancel the session instead.');
            }
            if ($items->contains(fn (OrderItem $item) => ! in_array($item->trang_thai_bep, $withdrawn, true) || (int) ($item->so_luong_tinh_tien ?? 0) > 0)) {
                throw new HttpException(409, 'Some items are still charged. Create the invoice instead.');
            }
            foreach ($lockedSession->orders as $order) {
                if ($this->ledger->paidAmount($order) > $this->ledger->tolerance()) {
                    throw new HttpException(409, 'Money was received for this session. Record the refund before finishing it.');
                }
            }

            foreach ($lockedSession->orders->where('trang_thai', '!=', OrderStatus::CANCELLED) as $order) {
                $order->update([
                    'trang_thai' => OrderStatus::CANCELLED,
                    'ly_do_huy' => $reason,
                    'ma_nguoi_huy' => $actor->ma_tai_khoan,
                    'huy_luc' => now(),
                ]);
                $this->ledger->syncOrderPayment($order->fresh());
            }

            $lockedSession->update([
                'trang_thai' => PosSessionStatus::CLOSED,
                'dong_luc' => now(),
                'kieu_ket_thuc' => SessionEndType::NO_CHARGE,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);
            $lockedSession->tables()->update(['trang_thai' => TableStatus::CLEANING->value]);
            $this->serviceRequests->closeForSession((int) $lockedSession->ma_phien);

            $reservation = $lockedSession->ma_dat_ban ? Reservation::lockForUpdate()->find($lockedSession->ma_dat_ban) : null;
            if ($reservation && $reservation->trang_thai->canTransitionTo(ReservationStatus::COMPLETED)) {
                $reservation->update(['trang_thai' => ReservationStatus::COMPLETED, 'xu_ly_luc' => now()]);
            }

            $this->audit->record($actor, 'SESSION_CLOSED_NO_CHARGE', 'SESSION', (int) $lockedSession->ma_phien, $reason, null, [
                'withdrawnItems' => $items->count(),
                'tables' => $lockedSession->tables->pluck('ten_ban')->values()->all(),
            ]);

            return $this->loadSession($lockedSession->ma_phien);
        });
    }

    /**
     * Khách bỏ đi chưa trả đủ (ADR-027): chỉ ADMIN. Món chưa chế biến xong bị hủy không thu
     * tiền; tiền đã nhận được giữ nguyên; phần còn thiếu ghi riêng là thất thu (không tính
     * vào tiền đã thu, không giả lập PAID).
     */
    public function writeOff(ServiceSession $session, Account $admin, string $reason): ServiceSession
    {
        if (! $admin->isAdmin()) {
            throw new HttpException(403, 'Only an administrator can close a session as unpaid.');
        }

        return DB::transaction(function () use ($session, $admin, $reason): ServiceSession {
            $lockedSession = ServiceSession::with(['tables', 'orders.items'])->lockForUpdate()->findOrFail($session->ma_phien);
            if (! in_array($lockedSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
                throw new HttpException(409, 'This service session has already ended.');
            }

            $order = $lockedSession->orders->first(fn (Order $sessionOrder) => $sessionOrder->trang_thai !== OrderStatus::CANCELLED);
            if (! $order) {
                throw new HttpException(409, 'Nothing was ordered. Cancel the session instead.');
            }

            OrderItem::query()->where('ma_don_hang', $order->ma_don_hang)
                ->whereIn('trang_thai_bep', [KitchenItemStatus::DRAFT->value, KitchenItemStatus::SENT->value, KitchenItemStatus::QUEUED->value, KitchenItemStatus::PREPARING->value])
                ->update([
                    'trang_thai_bep' => KitchenItemStatus::CANCELLED->value,
                    'so_luong_tinh_tien' => 0,
                    'loai_ly_do_huy' => ItemVoidReason::NOT_SERVED->value,
                    'ly_do_huy' => 'Khách rời đi trước khi nhận món',
                    'ma_nguoi_huy' => $admin->ma_tai_khoan,
                    'huy_luc' => now(),
                ]);
            app(PosOrderService::class)->recalculate($order->fresh());
            $order->refresh();

            $invoice = Invoice::query()->where('ma_phien', $lockedSession->ma_phien)->lockForUpdate()->first();
            if (! $invoice) {
                $invoice = Invoice::create([
                    'ma_phien' => $lockedSession->ma_phien,
                    'ma_don_hang' => $order->ma_don_hang,
                    'phien_ban' => 1,
                    'tam_tinh' => $order->tong_tien,
                    'giam_gia' => 0,
                    'tong_thanh_toan' => $order->tong_tien,
                    'trang_thai' => InvoiceStatus::OPEN,
                    'tao_luc' => now(),
                ]);
                $this->recordVersion($invoice);
            } elseif ($invoice->trang_thai !== InvoiceStatus::OPEN || abs((float) $invoice->tong_thanh_toan - (float) $order->tong_tien) >= 0.005) {
                if ($invoice->trang_thai === InvoiceStatus::OPEN) {
                    $this->voidCurrentVersion($invoice, $admin, 'Tính lại khi kết thúc thất thu');
                }
                $invoice->update(['tam_tinh' => $order->tong_tien, 'tong_thanh_toan' => $order->tong_tien, 'trang_thai' => InvoiceStatus::OPEN, 'tao_luc' => now(), 'phien_ban' => $invoice->phien_ban + 1]);
                $this->recordVersion($invoice->fresh());
            }

            $this->ledger->syncOrderPayment($order);
            $shortfall = $this->ledger->outstanding($order);
            if ($shortfall <= 0) {
                throw new HttpException(409, 'Nothing is outstanding. Close the session normally.');
            }

            $invoice->update(['trang_thai' => InvoiceStatus::WRITTEN_OFF, 'so_tien_that_thu' => $shortfall]);
            InvoiceVersion::query()->where('ma_hoa_don', $invoice->ma_hoa_don)->where('phien_ban', $invoice->phien_ban)
                ->update(['trang_thai' => InvoiceStatus::WRITTEN_OFF->value]);

            $lockedSession->forceFill([
                'so_tien_that_thu' => $shortfall,
                'ly_do_that_thu' => $reason,
                'ma_nguoi_duyet_that_thu' => $admin->ma_tai_khoan,
            ])->save();
            $this->finishSession($lockedSession, $order->fresh(['items']), SessionEndType::WRITE_OFF);

            $this->audit->record($admin, 'SESSION_WRITTEN_OFF', 'SESSION', (int) $lockedSession->ma_phien, $reason, null, [
                'invoiceId' => (int) $invoice->ma_hoa_don,
                'orderTotal' => (float) $order->tong_tien,
                'collected' => $this->ledger->paidAmount($order),
                'shortfall' => $shortfall,
            ]);

            return $this->loadSession($lockedSession->ma_phien);
        });
    }

    private function finishSession(ServiceSession $lockedSession, Order $order, SessionEndType $endType): void
    {
        OrderItem::where('ma_don_hang', $order->ma_don_hang)
            ->where('trang_thai_bep', KitchenItemStatus::READY->value)
            ->update(['trang_thai_bep' => KitchenItemStatus::SERVED->value, 'bep_cap_nhat_luc' => now()]);
        $order->update([
            'trang_thai' => OrderStatus::COMPLETED,
            'trang_thai_giao_nhan' => FulfillmentStatus::SERVED,
            'ban_giao_luc' => now(),
        ]);
        $lockedSession->update([
            'trang_thai' => PosSessionStatus::CLOSED,
            'dong_luc' => now(),
            'kieu_ket_thuc' => $endType,
            'phien_ban' => $lockedSession->phien_ban + 1,
        ]);
        $lockedSession->tables()->update(['trang_thai' => TableStatus::CLEANING->value]);
        $this->serviceRequests->closeForSession((int) $lockedSession->ma_phien);

        $reservation = $lockedSession->ma_dat_ban
            ? Reservation::lockForUpdate()->find($lockedSession->ma_dat_ban)
            : null;
        if ($reservation && $reservation->trang_thai->canTransitionTo(ReservationStatus::COMPLETED)) {
            $reservation->update(['trang_thai' => ReservationStatus::COMPLETED, 'xu_ly_luc' => now()]);
        }
    }

    private function assertTransferCanBeMerged(MoneyTransaction $transaction, Order $order): void
    {
        $appliedOrders = array_keys($this->ledger->netAllocationsByOrder($transaction));
        if ($appliedOrders !== [] && ! in_array((int) $order->ma_don_hang, $appliedOrders, true)) {
            throw new HttpException(409, 'This bank transaction is already applied to another order.');
        }
    }

    private function voidCurrentVersion(Invoice $invoice, ?Account $actor, string $reason): void
    {
        $invoice->update(['trang_thai' => InvoiceStatus::VOID]);
        InvoiceVersion::query()
            ->where('ma_hoa_don', $invoice->ma_hoa_don)
            ->where('phien_ban', $invoice->phien_ban)
            ->update([
                'trang_thai' => InvoiceStatus::VOID->value,
                'huy_luc' => now(),
                'ly_do_huy' => mb_substr($reason, 0, 255),
                'ma_nguoi_huy' => $actor?->ma_tai_khoan,
            ]);
        $this->audit->record($actor, 'INVOICE_VERSION_VOIDED', 'INVOICE', (int) $invoice->ma_hoa_don, $reason, ['version' => (int) $invoice->phien_ban, 'total' => (float) $invoice->tong_thanh_toan]);
    }

    private function recordVersion(Invoice $invoice): void
    {
        InvoiceVersion::query()->updateOrCreate(
            ['ma_hoa_don' => $invoice->ma_hoa_don, 'phien_ban' => $invoice->phien_ban],
            ['tong_thanh_toan' => $invoice->tong_thanh_toan, 'trang_thai' => InvoiceStatus::OPEN->value, 'tao_luc' => now()]
        );
    }

    private function loadSession(int $sessionId): ServiceSession
    {
        return ServiceSession::with([
            'staff.userProfile',
            'tables',
            'reservation.account.userProfile',
            'orders.items.dish',
            'orders.items.tickets',
            'invoice.payment',
            'invoice.versions',
            'approvals.requester.userProfile',
            'approvals.approver.userProfile',
            'serviceRequests' => fn ($query) => $query->orderByDesc('ma_yeu_cau')->limit(30),
            'visits' => fn ($query) => $query->where('trang_thai', TableVisitStatus::ACTIVE->value),
        ])->findOrFail($sessionId);
    }
}
