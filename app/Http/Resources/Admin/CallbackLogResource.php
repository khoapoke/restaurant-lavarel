<?php

namespace App\Http\Resources\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallbackLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
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

        $decodedLog = null;
        if (!empty($this->log_callback)) {
            $json = json_decode($this->log_callback, true);
            $decodedLog = (json_last_error() === JSON_ERROR_NONE) ? $json : $this->log_callback;
        }

        return [
            'paymentId' => (int) $this->paymentId,
            'orderId' => (int) $this->orderId,
            'amount' => (float) $this->amount,
            'paymentMethod' => $pMethod,
            'status' => $pStatus,
            'transactionCode' => $this->transactionCode,
            'callbackLog' => $decodedLog,
        ];
    }
}
