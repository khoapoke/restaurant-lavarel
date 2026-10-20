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
        ];
    }
}
