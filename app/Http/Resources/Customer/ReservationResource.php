<?php

namespace App\Http\Resources\Customer;

use App\Enums\ReservationStatus;
use App\Services\Customer\ReservationService;
use App\Services\Shared\ReservationSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lượt đặt bàn cho khách. Không bao giờ trả ghi chú nội bộ của nhà hàng.
 */
class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schedule = app(ReservationSchedule::class);
        $seated = isset($this->is_seated) ? (bool) $this->is_seated : ($this->trang_thai === ReservationStatus::CONFIRMED && $schedule->isSeated($this->resource));
        $effective = $this->trang_thai instanceof ReservationStatus ? $schedule->effectiveStatus($this->resource, $seated) : null;
        $status = $effective?->name ?? (ReservationStatus::tryFrom((string) $this->trang_thai)?->name ?? (string) $this->trang_thai);

        return [
            'reservationId' => (int) $this->ma_dat_ban,
            'reservationTime' => $this->thoi_gian?->format('Y-m-d\TH:i:s'),
            'guestCount' => (int) $this->so_luong_khach,
            'status' => $status,
            'note' => $this->ghi_chu,
            'isSeated' => $seated,
            'holdUntil' => $effective === ReservationStatus::CONFIRMED ? $schedule->holdUntil($this->resource)->format('Y-m-d\TH:i:s') : null,
            'statusReason' => in_array($effective, [ReservationStatus::REJECTED, ReservationStatus::CANCELLED], true) ? $this->ly_do_xu_ly : null,
            'canModify' => $effective !== null
                && $effective->isActive()
                && ! $seated
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
