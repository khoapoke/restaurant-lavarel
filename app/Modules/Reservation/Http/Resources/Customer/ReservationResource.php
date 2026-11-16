<?php

namespace App\Modules\Reservation\Http\Resources\Customer;

use App\Modules\Core\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusName = $this->status instanceof ReservationStatus
            ? $this->status->name
            : (ReservationStatus::tryFrom($this->status)?->name ?? (string) $this->status);

        $reservationTime = null;
        if ($this->thoi_gian) {
            $reservationTime = is_string($this->thoi_gian)
                ? $this->thoi_gian
                : $this->thoi_gian->format('Y-m-d\TH:i:s');
        }

        $table = $this->relationLoaded('diningTable') ? $this->diningTable : $this->diningTable()->first();

        $depositAmount = (float) ($this->tien_coc ?? 0);
        $isDepositPaid = (bool) ($this->da_thanh_toan_coc ?? false);
        $depositDeadline = $this->han_chot_coc ? $this->han_chot_coc->toIso8601String() : null;

        $qrCodeUrl = null;
        if ($depositAmount > 0 && !$isDepositPaid) {
            $bankBin = env('VIETQR_BANK_BIN', '970436');
            $accountNo = env('VIETQR_ACCOUNT_NO', '1029384756');
            $template = env('VIETQR_TEMPLATE', 'compact2');
            $transferContent = "DB{$this->reservationId} COC";
            $qrCodeUrl = "https://img.vietqr.io/image/{$bankBin}-{$accountNo}-{$template}.png?amount="
                . ((int) $depositAmount) . '&addInfo=' . urlencode($transferContent);
        }

        return [
            'reservationId' => (int) $this->reservationId,
            'reservationTime' => $reservationTime,
            'guestCount' => (int) $this->guestCount,
            'status' => $statusName,
            'note' => $this->note,
            'depositAmount' => $depositAmount,
            'isDepositPaid' => $isDepositPaid,
            'depositDeadline' => $depositDeadline,
            'qrCodeUrl' => $qrCodeUrl,
            'table' => $table ? new TableResource($table) : null,
        ];
    }
}

