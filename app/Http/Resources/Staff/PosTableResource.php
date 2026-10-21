<?php

namespace App\Http\Resources\Staff;

use App\Enums\PosSessionStatus;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeSession = $this->serviceSessions
            ->first(fn ($session) => in_array($session->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true));
        $upcomingReservation = $this->relationLoaded('reservations') ? $this->reservations->first() : null;

        return [
            'tableId' => (int) $this->ma_ban,
            'tableName' => $this->ten_ban,
            'capacity' => (int) $this->suc_chua,
            'status' => $this->trang_thai->name,
            'location' => $this->vi_tri,
            'activeSession' => $activeSession ? [
                'sessionId' => (int) $activeSession->ma_phien,
                'status' => $activeSession->trang_thai->name,
                'guestCount' => (int) $activeSession->so_luong_khach,
                'openedAt' => $activeSession->mo_luc?->toIso8601String(),
                'version' => (int) $activeSession->phien_ban,
                'staffName' => $activeSession->staff?->userProfile?->ho_ten
                    ?? $activeSession->staff?->ten_dang_nhap,
                'reservationId' => $activeSession->ma_dat_ban ? (int) $activeSession->ma_dat_ban : null,
            ] : null,
            'upcomingReservation' => $upcomingReservation ? [
                'reservationId' => (int) $upcomingReservation->ma_dat_ban,
                'reservationTime' => $upcomingReservation->thoi_gian?->format('Y-m-d\TH:i:s'),
                'guestCount' => (int) $upcomingReservation->so_luong_khach,
                'note' => $upcomingReservation->ghi_chu,
                'customerName' => $upcomingReservation->account?->userProfile?->ho_ten
                    ?? $upcomingReservation->account?->ten_dang_nhap,
                'customerPhone' => $upcomingReservation->account?->userProfile?->so_dien_thoai,
                'isWithinSeatingWindow' => abs(now()->diffInMinutes($upcomingReservation->thoi_gian, false)) <= TableStatusSynchronizer::SLOT_MINUTES,
            ] : null,
        ];
    }
}
