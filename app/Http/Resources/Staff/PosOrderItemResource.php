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
            'subTotal' => round((float) $this->don_gia * (int) $this->so_luong, 2),
            'note' => $this->ghi_chu,
            'kitchenStatus' => $this->trang_thai_bep->name,
            'source' => $this->whenLoaded('tickets', fn () => ($this->tickets->first()?->nguon ?? KitchenTicketSource::POS)->name),
            'sentAt' => $this->whenLoaded('tickets', fn () => $this->tickets->first()?->gui_luc?->toIso8601String()),
            'statusChangedAt' => $this->bep_cap_nhat_luc?->toIso8601String(),
        ];
    }
}
