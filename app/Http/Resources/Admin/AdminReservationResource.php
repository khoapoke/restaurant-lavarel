<?php

namespace App\Http\Resources\Admin;

use App\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->trang_thai instanceof ReservationStatus
            ? $this->trang_thai->name
            : (ReservationStatus::tryFrom((string) $this->trang_thai)?->name ?? (string) $this->trang_thai);

        return [
            'reservationId' => (int) $this->ma_dat_ban,
            'reservationTime' => $this->thoi_gian?->format('Y-m-d\TH:i:s'),
            'guestCount' => (int) $this->so_luong_khach,
            'status' => $status,
            'note' => $this->ghi_chu,
            'customer' => $this->whenLoaded('account', fn (): array => [
                'accountId' => (int) $this->account->ma_tai_khoan,
                'username' => $this->account->ten_dang_nhap,
                'fullName' => $this->account->userProfile?->ho_ten,
                'phoneNumber' => $this->account->userProfile?->so_dien_thoai,
            ]),
            'table' => $this->whenLoaded('diningTable', fn (): array => [
                'tableId' => (int) $this->diningTable->ma_ban,
                'tableName' => $this->diningTable->ten_ban,
                'capacity' => (int) $this->diningTable->suc_chua,
                'status' => $this->diningTable->trang_thai->name,
                'location' => $this->diningTable->vi_tri,
            ]),
        ];
    }
}
