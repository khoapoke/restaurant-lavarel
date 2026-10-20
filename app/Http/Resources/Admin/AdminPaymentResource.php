<?php

namespace App\Http\Resources\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->trang_thai instanceof PaymentStatus ? $this->trang_thai->name : (string) $this->trang_thai;
        $method = $this->phuong_thuc instanceof PaymentMethod ? $this->phuong_thuc->name : (string) $this->phuong_thuc;

        return [
            'paymentId' => (int) $this->ma_thanh_toan,
            'orderId' => (int) $this->ma_don_hang,
            'amount' => (float) $this->so_tien,
            'paymentMethod' => $method,
            'status' => $status,
            'transactionCode' => $this->ma_giao_dich,
            'refundReason' => $this->ly_do_hoan_tien,
            'callbackLog' => $this->log_callback,
            'orderDate' => $this->whenLoaded('order', fn () => $this->order?->ngay_dat_hang?->format('Y-m-d\TH:i:s')),
            'orderTotal' => $this->whenLoaded('order', fn () => $this->order ? (float) $this->order->tong_tien : null),
        ];
    }
}
