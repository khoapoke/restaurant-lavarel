<?php

namespace App\Modules\Reservation\Http\Resources\Admin;

use App\Modules\Core\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin.
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
        $account = $this->relationLoaded('account') ? $this->account : $this->account()->first();
        $userProfile = $account ? ($account->relationLoaded('userProfile') ? $account->userProfile : $account->userProfile()->first()) : null;

        $depositAmount = (float) ($this->tien_coc ?? 0);
        $isDepositPaid = (bool) ($this->da_thanh_toan_coc ?? false);
        $depositDeadline = $this->han_chot_coc ? $this->han_chot_coc->toIso8601String() : null;

        return [
            'reservationId' => (int) $this->reservationId,
            'reservationTime' => $reservationTime,
            'guestCount' => (int) $this->guestCount,
            'status' => $statusName,
            'note' => $this->note,
            'depositAmount' => $depositAmount,
            'isDepositPaid' => $isDepositPaid,
            'depositDeadline' => $depositDeadline,
            'table' => $table ? new AdminTableResource($table) : null,
            'customer' => $account ? [
                'accountId' => (int) $account->ma_tai_khoan,
                'username' => $account->ten_dang_nhap,
                'fullName' => $userProfile?->fullName,
                'phoneNumber' => $userProfile?->phoneNumber,
            ] : null,
        ];
    }
}

