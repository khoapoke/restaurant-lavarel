<?php

namespace App\Http\Resources\Staff;

use App\Enums\InvoiceStatus;
use App\Models\Order;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Payments\VietQrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vietQr = app(VietQrService::class);
        $ledger = app(PaymentLedgerService::class);
        $order = Order::query()->find($this->ma_don_hang);
        $paid = $order ? $ledger->paidAmount($order) : 0.0;
        $outstanding = $order ? $ledger->outstanding($order) : (float) $this->tong_thanh_toan;

        return [
            'invoiceId' => (int) $this->ma_hoa_don,
            'version' => (int) $this->phien_ban,
            'invoiceNumber' => 'HD'.$this->ma_hoa_don.'-V'.$this->phien_ban,
            'status' => $this->trang_thai->name,
            'subTotal' => (float) $this->tam_tinh,
            'discountTotal' => (float) $this->giam_gia,
            'totalAmount' => (float) $this->tong_thanh_toan,
            'amountPaid' => $paid,
            'outstanding' => $this->trang_thai === InvoiceStatus::WRITTEN_OFF ? 0.0 : $outstanding,
            'writtenOffAmount' => (float) $this->so_tien_that_thu,
            'refundDue' => $order ? $ledger->refundDue($order) : 0.0,
            'createdAt' => $this->tao_luc?->toIso8601String(),
            'paidAt' => $this->thanh_toan_luc?->toIso8601String(),
            'qr' => $this->trang_thai === InvoiceStatus::OPEN && $outstanding > 0
                ? $vietQr->build($outstanding, $vietQr->invoiceTransferContent((int) $this->ma_hoa_don, (int) $this->phien_ban))
                : null,
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($version) => [
                'version' => (int) $version->phien_ban,
                'totalAmount' => (float) $version->tong_thanh_toan,
                'status' => $version->trang_thai->name,
                'createdAt' => $version->tao_luc?->toIso8601String(),
                'voidedAt' => $version->huy_luc?->toIso8601String(),
                'voidReason' => $version->ly_do_huy,
            ])->values()),
            'payment' => $this->payment ? [
                'paymentId' => (int) $this->payment->ma_thanh_toan,
                'method' => $this->payment->phuong_thuc->name,
                'status' => $this->payment->trang_thai->name,
                'amount' => (float) $this->payment->so_tien,
                'amountPaid' => (float) $this->payment->so_tien_da_thu,
                'transactionCode' => $this->payment->ma_giao_dich,
            ] : null,
        ];
    }
}
