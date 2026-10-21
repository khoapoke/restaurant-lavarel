<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sessionId' => (int) $this->ma_phien,
            'status' => $this->trang_thai->name,
            'guestCount' => (int) $this->so_luong_khach,
            'openedAt' => $this->mo_luc?->toIso8601String(),
            'closedAt' => $this->dong_luc?->toIso8601String(),
            'version' => (int) $this->phien_ban,
            'cancelReason' => $this->ly_do_huy,
            'reservation' => $this->whenLoaded('reservation', fn () => $this->reservation ? [
                'reservationId' => (int) $this->reservation->ma_dat_ban,
                'reservationTime' => $this->reservation->thoi_gian?->format('Y-m-d\TH:i:s'),
                'guestCount' => (int) $this->reservation->so_luong_khach,
                'status' => $this->reservation->trang_thai->name,
                'note' => $this->reservation->ghi_chu,
                'customerName' => $this->reservation->account?->userProfile?->ho_ten
                    ?? $this->reservation->account?->ten_dang_nhap,
                'customerPhone' => $this->reservation->account?->userProfile?->so_dien_thoai,
            ] : null),
            'staff' => [
                'accountId' => (int) $this->staff->ma_tai_khoan,
                'username' => $this->staff->ten_dang_nhap,
                'fullName' => $this->staff->userProfile?->ho_ten,
            ],
            'tables' => $this->tables->map(fn ($table) => [
                'tableId' => (int) $table->ma_ban,
                'tableName' => $table->ten_ban,
                'capacity' => (int) $table->suc_chua,
                'location' => $table->vi_tri,
                'status' => $table->trang_thai->name,
            ])->values(),
            'orders' => PosOrderResource::collection($this->whenLoaded('orders')),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? new InvoiceResource($this->invoice) : null),
            'serviceRequests' => $this->whenLoaded('serviceRequests', fn () => $this->serviceRequests->map(fn ($serviceRequest) => [
                'requestId' => (int) $serviceRequest->ma_yeu_cau,
                'type' => $serviceRequest->loai->name,
                'label' => $serviceRequest->loai->label(),
                'isPriority' => $serviceRequest->loai->isPriority(),
                'note' => $serviceRequest->ghi_chu,
                'preferredMethod' => $serviceRequest->phuong_thuc_mong_muon?->name,
                'status' => $serviceRequest->trang_thai->name,
                'createdAt' => $serviceRequest->tao_luc?->toIso8601String(),
                'acknowledgedAt' => $serviceRequest->nhan_luc?->toIso8601String(),
                'completedAt' => $serviceRequest->hoan_thanh_luc?->toIso8601String(),
            ])->values()),
            'liveDining' => $this->whenLoaded('visits', fn () => [
                'connectedGuests' => $this->visits->count(),
                'joinCode' => $this->ma_tham_gia,
                'joinCodeLocked' => $this->isJoinCodeLocked(),
                'qrUrl' => $this->tables->first()?->qrUrl(),
            ]),
        ];
    }
}
