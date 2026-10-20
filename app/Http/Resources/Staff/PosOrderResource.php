<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'orderId' => (int) $this->ma_don_hang,
            'channel' => $this->kenh->name,
            'status' => $this->trang_thai->name,
            'fulfillmentType' => $this->loai_phuc_vu->name,
            'fulfillmentStatus' => $this->trang_thai_giao_nhan->name,
            'note' => $this->ghi_chu,
            'totalAmount' => (float) $this->tong_tien,
            'version' => (int) $this->phien_ban,
            'createdAt' => $this->ngay_dat_hang?->toIso8601String(),
            'items' => PosOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
