<?php

namespace App\Http\Resources\Admin;

use App\Enums\ReservationStatus;
use App\Services\Shared\ReservationSchedule;
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
        $schedule = app(ReservationSchedule::class);
        $seated = (bool) ($this->is_seated ?? false);
        $status = $this->trang_thai instanceof ReservationStatus
            ? $schedule->effectiveStatus($this->resource, $seated)->name
            : (ReservationStatus::tryFrom((string) $this->trang_thai)?->name ?? (string) $this->trang_thai);
        $session = $this->relationLoaded('serviceSessions')
            ? $this->serviceSessions->first(fn ($candidate) => $candidate->trang_thai->name !== 'CANCELLED')
            : null;
        $active = $this->trang_thai instanceof ReservationStatus && $this->trang_thai->isActive();

        return [
            'reservationId' => (int) $this->ma_dat_ban,
            'reservationTime' => $this->thoi_gian?->format('Y-m-d\TH:i:s'),
            'expectedEndTime' => $this->thoi_gian ? $schedule->end($this->resource)->format('Y-m-d\TH:i:s') : null,
            'guestCount' => (int) $this->so_luong_khach,
            'status' => $status,
            'storedStatus' => $this->trang_thai instanceof ReservationStatus ? $this->trang_thai->name : null,
            // Ghi chú của khách và ghi chú nội bộ được trả riêng (RSV-18).
            'note' => $this->ghi_chu,
            'internalNote' => $this->ghi_chu_noi_bo,
            'isSeated' => $seated,
            'actualGuestCount' => $session ? (int) $session->so_luong_khach : null,
            'seatedTables' => $session && $session->relationLoaded('tables') ? $session->tables->pluck('ten_ban')->values() : [],
            'hold' => $active && $this->thoi_gian ? [
                'holdUntil' => $schedule->holdUntil($this->resource)->format('Y-m-d\TH:i:s'),
                'seatingOpensAt' => $schedule->seatingOpensAt($this->resource)->format('Y-m-d\TH:i:s'),
                'autoNoShowAt' => $schedule->autoNoShowAt($this->resource)->format('Y-m-d\TH:i:s'),
                'holdExpired' => $schedule->holdExpired($this->resource),
            ] : null,
            'extension' => $this->gia_han_den ? [
                'until' => $this->gia_han_den->format('Y-m-d\TH:i:s'),
                'reason' => $this->ly_do_gia_han,
                'by' => $this->relationLoaded('extendedBy') ? $this->extendedBy?->displayName() : null,
            ] : null,
            'resolution' => $this->xu_ly_luc ? [
                'at' => $this->xu_ly_luc->format('Y-m-d\TH:i:s'),
                'reason' => $this->ly_do_xu_ly,
                'by' => $this->relationLoaded('handledBy') ? ($this->handledBy?->displayName() ?? 'Hệ thống') : null,
            ] : null,
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
