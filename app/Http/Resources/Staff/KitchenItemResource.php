<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->order?->serviceSession;

        return [
            'itemId' => (int) $this->ma_chi_tiet,
            'orderId' => (int) $this->ma_don_hang,
            'sessionId' => $session ? (int) $session->ma_phien : null,
            'tableNames' => $session?->tables?->pluck('ten_ban')->values() ?? [],
            'dishName' => $this->ten_mon_snapshot,
            'quantity' => (int) $this->so_luong,
            'note' => $this->ghi_chu,
            'status' => $this->trang_thai_bep->name,
            'imageUrl' => $this->dish?->hinh_anh,
            'orderedAt' => $this->order?->ngay_dat_hang?->toIso8601String(),
        ];
    }
}
