<?php

namespace App\Services\Staff;

use App\Enums\FulfillmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Dining\ServiceRequestService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingService
{
    public function __construct(private readonly ServiceRequestService $serviceRequests) {}

    public function createInvoice(ServiceSession $session, ?int $staffId = null): Invoice
    {
        return DB::transaction(function () use ($session, $staffId) {
            $lockedSession = ServiceSession::lockForUpdate()->findOrFail($session->ma_phien);
            if (! in_array($lockedSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
                throw new HttpException(409, 'This service session cannot be invoiced.');
            }

            $existing = Invoice::where('ma_phien', $lockedSession->ma_phien)->lockForUpdate()->first();
            if ($existing && $existing->trang_thai !== InvoiceStatus::VOID) {
                return $existing->load('payment');
            }

            $order = Order::with('items')->where('ma_phien', $lockedSession->ma_phien)
                ->where('trang_thai', '!=', OrderStatus::CANCELLED->value)
                ->first();
            $billableItems = $order?->items->reject(fn (OrderItem $item) => in_array($item->trang_thai_bep, [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true));
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
                $existing->update($amounts);
                $invoice = $existing;
            } else {
                $invoice = Invoice::create([
                    'ma_phien' => $lockedSession->ma_phien,
                    'ma_don_hang' => $order->ma_don_hang,
                    ...$amounts,
                ]);
            }

            $lockedSession->update([
                'trang_thai' => PosSessionStatus::AWAITING_PAYMENT,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);
            $this->serviceRequests->acknowledgePaymentRequests((int) $lockedSession->ma_phien, $staffId);

            return $invoice->load('payment');
        });
    }

    /**
     * Void an unpaid invoice so the table can order more dishes in the same session.
     */
    public function reopen(ServiceSession $session): ServiceSession
    {
        return DB::transaction(function () use ($session): ServiceSession {
            $lockedSession = ServiceSession::lockForUpdate()->findOrFail($session->ma_phien);
            if ($lockedSession->trang_thai !== PosSessionStatus::AWAITING_PAYMENT) {
                throw new HttpException(409, 'Only a session awaiting payment can be reopened for more orders.');
            }

            $invoice = Invoice::where('ma_phien', $lockedSession->ma_phien)->lockForUpdate()->first();
            if (! $invoice || $invoice->trang_thai !== InvoiceStatus::OPEN) {
                throw new HttpException(409, 'A paid invoice cannot be reopened.');
            }

            $invoice->update(['trang_thai' => InvoiceStatus::VOID]);
            $lockedSession->update([
                'trang_thai' => PosSessionStatus::OPEN,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);

            return $this->loadSession($lockedSession->ma_phien);
        });
    }

    public function pay(Invoice $invoice, array $data, ?int $staffId = null): Payment
    {
        $existing = Payment::where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($invoice, $data, $staffId) {
            $lockedInvoice = Invoice::lockForUpdate()->findOrFail($invoice->ma_hoa_don);
            if ($lockedInvoice->trang_thai === InvoiceStatus::PAID) {
                return $lockedInvoice->payment;
            }
            if ($lockedInvoice->trang_thai !== InvoiceStatus::OPEN) {
                throw new HttpException(409, 'This invoice has been voided. Create a new invoice before taking payment.');
            }

            $amount = round((float) $data['amount'], 2);
            $expected = round((float) $lockedInvoice->tong_thanh_toan, 2);
            if (abs($amount - $expected) > 0.001) {
                throw new HttpException(409, "Payment amount must equal {$expected}.");
            }

            $method = PaymentMethod::from(match ($data['method']) {
                'BANK_TRANSFER_QR' => PaymentMethod::BANK_TRANSFER_QR->value,
                default => PaymentMethod::CASH->value,
            });

            $transactionCode = trim((string) ($data['transactionCode'] ?? ''));
            $attributes = [
                'so_tien' => $amount,
                'phuong_thuc' => $method,
                'trang_thai' => PaymentStatus::PAID,
                'ma_hoa_don' => $lockedInvoice->ma_hoa_don,
                'ma_giao_dich' => $transactionCode !== ''
                    ? $transactionCode
                    : ($method === PaymentMethod::CASH ? 'POS-CASH-' : 'POS-QR-').$lockedInvoice->ma_hoa_don.'-'.now()->format('YmdHis'),
                'idempotency_key' => $data['idempotencyKey'],
            ];

            $existingOrderPayment = Payment::where('ma_don_hang', $lockedInvoice->ma_don_hang)->lockForUpdate()->first();
            if ($existingOrderPayment) {
                $existingOrderPayment->update($attributes);
                $payment = $existingOrderPayment;
            } else {
                $payment = Payment::create([...$attributes, 'ma_don_hang' => $lockedInvoice->ma_don_hang]);
            }
            $lockedInvoice->update(['trang_thai' => InvoiceStatus::PAID, 'thanh_toan_luc' => now()]);
            $this->serviceRequests->completePaymentRequests((int) $lockedInvoice->ma_phien, $staffId);

            return $payment;
        });
    }

    public function close(ServiceSession $session, int $expectedVersion): ServiceSession
    {
        return DB::transaction(function () use ($session, $expectedVersion) {
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
            $unfinished = $order->items->contains(fn ($item) => ! in_array($item->trang_thai_bep, [KitchenItemStatus::READY, KitchenItemStatus::SERVED, KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true));
            if ($unfinished) {
                throw new HttpException(409, 'All kitchen items must be READY or SERVED before closing.');
            }

            OrderItem::where('ma_don_hang', $order->ma_don_hang)
                ->where('trang_thai_bep', KitchenItemStatus::READY->value)
                ->update(['trang_thai_bep' => KitchenItemStatus::SERVED->value]);
            $order->update([
                'trang_thai' => OrderStatus::COMPLETED,
                'trang_thai_giao_nhan' => FulfillmentStatus::SERVED,
            ]);
            $lockedSession->update([
                'trang_thai' => PosSessionStatus::CLOSED,
                'dong_luc' => now(),
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);
            $lockedSession->tables()->update(['trang_thai' => TableStatus::CLEANING->value]);
            $this->serviceRequests->closeForSession((int) $lockedSession->ma_phien);

            $reservation = $lockedSession->ma_dat_ban
                ? Reservation::lockForUpdate()->find($lockedSession->ma_dat_ban)
                : null;
            if ($reservation && $reservation->trang_thai->canTransitionTo(ReservationStatus::COMPLETED)) {
                $reservation->update(['trang_thai' => ReservationStatus::COMPLETED]);
            }

            return $this->loadSession($lockedSession->ma_phien);
        });
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
            'serviceRequests' => fn ($query) => $query->orderByDesc('ma_yeu_cau')->limit(30),
            'visits' => fn ($query) => $query->where('trang_thai', TableVisitStatus::ACTIVE->value),
        ])->findOrFail($sessionId);
    }
}
