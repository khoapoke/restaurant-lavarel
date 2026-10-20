<?php

namespace App\Http\Resources\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pMethod = $this->phuong_thuc instanceof PaymentMethod
            ? $this->phuong_thuc->name
            : (PaymentMethod::tryFrom($this->phuong_thuc)?->name ?? (string) $this->phuong_thuc);

        $pStatus = $this->trang_thai instanceof PaymentStatus
            ? $this->trang_thai->name
            : (PaymentStatus::tryFrom($this->trang_thai)?->name ?? (string) $this->trang_thai);

        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();
        $account = $order ? ($order->relationLoaded('account') ? $order->account : $order->account()->first()) : null;
        $profile = $account ? ($account->relationLoaded('userProfile') ? $account->userProfile : $account->userProfile()->first()) : null;

        $orderDate = null;
        if ($order && $order->ngay_dat_hang) {
            $orderDate = is_string($order->ngay_dat_hang)
                ? $order->ngay_dat_hang
                : $order->ngay_dat_hang->format('Y-m-d\TH:i:s');
        }

        return [
            'paymentId' => (int) $this->paymentId,
            'orderId' => (int) $this->orderId,
            'amount' => (float) $this->amount,
            'paymentMethod' => $pMethod,
            'status' => $pStatus,
            'transactionCode' => $this->transactionCode,
            'refundReason' => $this->refundReason,
            'orderDate' => $orderDate,
            'customerName' => $profile?->fullName ?? $account?->username,
            'customerPhone' => $profile?->phoneNumber,
        ];
    }
}
