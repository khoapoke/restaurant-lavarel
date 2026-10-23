<?php

namespace App\Http\Resources\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\Payments\PaymentLedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Bản tổng hợp thanh toán của một đơn (THANH_TOAN), tính từ sổ giao dịch. */
class AdminPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->trang_thai instanceof PaymentStatus ? $this->trang_thai->name : (string) $this->trang_thai;
        $method = $this->phuong_thuc instanceof PaymentMethod ? $this->phuong_thuc->name : (string) $this->phuong_thuc;
        $ledger = app(PaymentLedgerService::class);
        $order = $this->relationLoaded('order') ? $this->order : null;

        return [
            'paymentId' => (int) $this->ma_thanh_toan,
            'orderId' => (int) $this->ma_don_hang,
            'amount' => (float) $this->so_tien,
            'amountPaid' => (float) $this->so_tien_da_thu,
            'amountRefunded' => (float) $this->so_tien_da_hoan,
            'outstanding' => $order ? $ledger->outstanding($order) : null,
            'refundDue' => $order ? $ledger->refundDue($order) : null,
            'paymentMethod' => $method,
            'status' => $status,
            'transactionCode' => $this->ma_giao_dich,
            'refundReason' => $this->ly_do_hoan_tien,
            'paidAt' => $this->thanh_toan_luc?->toIso8601String(),
            'orderDate' => $this->whenLoaded('order', fn () => $this->order?->ngay_dat_hang?->format('Y-m-d\\TH:i:s')),
            'orderTotal' => $this->whenLoaded('order', fn () => $this->order ? (float) $this->order->tong_tien : null),
            'orderStatus' => $this->whenLoaded('order', fn () => $this->order?->trang_thai?->name),
            'cancellationDecision' => $this->whenLoaded('order', fn () => $this->order?->quyet_dinh_tien_huy),
            'fulfillmentType' => $this->whenLoaded('order', fn () => $this->order?->loai_phuc_vu?->name),
            'invoiceId' => $this->ma_hoa_don ? (int) $this->ma_hoa_don : null,
            'payableAmount' => $order ? $order->payableAmount() : null,
            'compensationAmount' => $order ? (float) ($order->so_tien_giam_tru ?? 0) : null,
            'lastReceipt' => $this->whenLoaded('lastReceipt', fn () => [
                'transactionId' => (int) $this->lastReceipt->ma_giao_dich_tien,
                'amount' => (float) $this->lastReceipt->so_tien,
                'applied' => (float) ($this->last_receipt_applied ?? 0),
                'flag' => $this->lastReceipt->canh_bao?->name,
                'reconciliation' => $this->lastReceipt->trang_thai_doi_soat->name,
            ]),
        ];
    }
}
