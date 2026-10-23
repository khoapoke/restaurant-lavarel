<?php

namespace App\Http\Resources\Staff;

use App\Enums\KitchenTicketSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'itemId' => (int) $this->ma_chi_tiet,
            'dishId' => (int) $this->ma_mon_an,
            'dishName' => $this->ten_mon_snapshot,
            'imageUrl' => $this->dish?->hinh_anh,
            'quantity' => (int) $this->so_luong,
            'unitPrice' => (float) $this->don_gia,
            'billedQuantity' => (int) ($this->so_luong_tinh_tien ?? $this->so_luong),
            'subTotal' => $this->billedAmount(),
            'note' => $this->ghi_chu,
            'kitchenStatus' => $this->trang_thai_bep->name,
            'source' => $this->whenLoaded('tickets', fn () => ($this->tickets->first()?->nguon ?? KitchenTicketSource::POS)->name),
            'sentAt' => $this->whenLoaded('tickets', fn () => $this->tickets->first()?->gui_luc?->toIso8601String()),
            'statusChangedAt' => $this->bep_cap_nhat_luc?->toIso8601String(),
            'lineType' => $this->loai_dong?->name ?? 'NORMAL',
            'originalItemId' => $this->ma_chi_tiet_goc ? (int) $this->ma_chi_tiet_goc : null,
            'voidReason' => $this->loai_ly_do_huy?->name,
            'voidReasonLabel' => $this->loai_ly_do_huy?->label(),
            'voidNote' => $this->ly_do_huy,
            'voidedAt' => $this->huy_luc?->toIso8601String(),
            'waived' => (bool) $this->mien_tien,
            'requiresApprovalToVoid' => in_array($this->trang_thai_bep?->value, ['PREPARING', 'READY', 'SERVED'], true),
        ];
    }
}
