<?php

namespace App\Http\Resources\Customer;

use App\Enums\ReservationStatus;
use App\Services\Customer\ReservationService;
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
        $status = $this->trang_thai instanceof ReservationStatus
            ? $this->trang_thai->name
            : (ReservationStatus::tryFrom((string) $this->trang_thai)?->name ?? (string) $this->trang_thai);

        return [
            'reservationId' => (int) $this->ma_dat_ban,
            'reservationTime' => $this->thoi_gian?->format('Y-m-d\TH:i:s'),
            'guestCount' => (int) $this->so_luong_khach,
            'status' => $status,
            'note' => $this->ghi_chu,
            'canModify' => $this->trang_thai instanceof ReservationStatus
                && $this->trang_thai->isActive()
                && $this->thoi_gian !== null
                && now()->diffInMinutes($this->thoi_gian, false) >= ReservationService::MODIFY_CUTOFF_MINUTES,
            'table' => $this->whenLoaded('diningTable', fn (): array => [
                'tableId' => (int) $this->diningTable->ma_ban,
                'tableName' => $this->diningTable->ten_ban,
                'capacity' => (int) $this->diningTable->suc_chua,
                'location' => $this->diningTable->vi_tri,
            ]),
        ];
    }
}
