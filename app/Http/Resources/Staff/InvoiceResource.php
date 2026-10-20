<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'invoiceId' => (int) $this->ma_hoa_don,
            'status' => $this->trang_thai->name,
            'subTotal' => (float) $this->tam_tinh,
            'discountTotal' => (float) $this->giam_gia,
            'totalAmount' => (float) $this->tong_thanh_toan,
            'createdAt' => $this->tao_luc?->toIso8601String(),
            'paidAt' => $this->thanh_toan_luc?->toIso8601String(),
            'payment' => $this->payment ? [
                'paymentId' => (int) $this->payment->ma_thanh_toan,
                'method' => $this->payment->phuong_thuc->name,
                'status' => $this->payment->trang_thai->name,
                'amount' => (float) $this->payment->so_tien,
                'transactionCode' => $this->payment->ma_giao_dich,
            ] : null,
        ];
    }
}
