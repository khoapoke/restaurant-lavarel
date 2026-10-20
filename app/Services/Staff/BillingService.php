<?php

namespace App\Services\Staff;

use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\TableStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ServiceSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingService
{
    public function createInvoice(ServiceSession $session): Invoice
    {
        return DB::transaction(function () use ($session) {
            $lockedSession = ServiceSession::lockForUpdate()->findOrFail($session->ma_phien);
            if (! in_array($lockedSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
                throw new HttpException(409, 'This service session cannot be invoiced.');
            }

            $existing = Invoice::where('ma_phien', $lockedSession->ma_phien)->first();
            if ($existing) {
                return $existing->load('payment');
            }

            $order = Order::with('items')->where('ma_phien', $lockedSession->ma_phien)->first();
            if (! $order || $order->items->isEmpty()) {
                throw new HttpException(409, 'Cannot create an invoice for an empty session.');
            }
            if ($order->items->contains(fn ($item) => $item->trang_thai_bep === KitchenItemStatus::DRAFT)) {
                throw new HttpException(409, 'Send all draft items to the kitchen before creating the invoice.');
            }

            $invoice = Invoice::create([
                'ma_phien' => $lockedSession->ma_phien,
                'ma_don_hang' => $order->ma_don_hang,
                'tam_tinh' => $order->tong_tien,
                'giam_gia' => 0,
                'tong_thanh_toan' => $order->tong_tien,
                'trang_thai' => InvoiceStatus::OPEN,
                'tao_luc' => now(),
            ]);
            $lockedSession->update([
                'trang_thai' => PosSessionStatus::AWAITING_PAYMENT,
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);

            return $invoice->load('payment');
        });
    }

    public function pay(Invoice $invoice, array $data): Payment
    {
        $existing = Payment::where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($invoice, $data) {
            $lockedInvoice = Invoice::lockForUpdate()->findOrFail($invoice->ma_hoa_don);
            if ($lockedInvoice->trang_thai === InvoiceStatus::PAID) {
                return $lockedInvoice->payment;
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

            $payment = Payment::create([
                'so_tien' => $amount,
                'phuong_thuc' => $method,
                'trang_thai' => PaymentStatus::PAID,
                'ma_don_hang' => $lockedInvoice->ma_don_hang,
                'ma_hoa_don' => $lockedInvoice->ma_hoa_don,
                'ma_giao_dich' => ($method === PaymentMethod::CASH ? 'POS-CASH-' : 'POS-QR-').$lockedInvoice->ma_hoa_don.'-'.now()->format('YmdHis'),
                'idempotency_key' => $data['idempotencyKey'],
            ]);
            $lockedInvoice->update(['trang_thai' => InvoiceStatus::PAID, 'thanh_toan_luc' => now()]);

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

            $order = $lockedSession->orders->first();
            if (! $order) {
                throw new HttpException(409, 'The service session has no order.');
            }
            $unfinished = $order->items->contains(fn ($item) => ! in_array($item->trang_thai_bep, [KitchenItemStatus::READY, KitchenItemStatus::SERVED], true));
            if ($unfinished) {
                throw new HttpException(409, 'All kitchen items must be READY or SERVED before closing.');
            }

            OrderItem::where('ma_don_hang', $order->ma_don_hang)
                ->where('trang_thai_bep', KitchenItemStatus::READY->value)
                ->update(['trang_thai_bep' => KitchenItemStatus::SERVED->value]);
            $order->update(['trang_thai' => OrderStatus::COMPLETED]);
            $lockedSession->update([
                'trang_thai' => PosSessionStatus::CLOSED,
                'dong_luc' => now(),
                'phien_ban' => $lockedSession->phien_ban + 1,
            ]);
            $lockedSession->tables()->update(['trang_thai' => TableStatus::CLEANING->value]);

            return $lockedSession->fresh(['staff.userProfile', 'tables', 'orders.items.dish', 'invoice.payment']);
        });
    }
}
