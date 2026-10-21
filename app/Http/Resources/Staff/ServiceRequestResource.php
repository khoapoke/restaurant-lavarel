<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'requestId' => (int) $this->ma_yeu_cau,
            'sessionId' => (int) $this->ma_phien,
            'tableId' => (int) $this->ma_ban,
            'tableName' => $this->diningTable?->ten_ban,
            'type' => $this->loai->name,
            'label' => $this->loai->label(),
            'isPriority' => $this->loai->isPriority(),
            'note' => $this->ghi_chu,
            'preferredMethod' => $this->phuong_thuc_mong_muon?->name,
            'status' => $this->trang_thai->name,
            'createdAt' => $this->tao_luc?->toIso8601String(),
            'acknowledgedAt' => $this->nhan_luc?->toIso8601String(),
            'completedAt' => $this->hoan_thanh_luc?->toIso8601String(),
            'handledBy' => $this->whenLoaded('handledBy', fn () => $this->handledBy
                ? ($this->handledBy->userProfile?->ho_ten ?? $this->handledBy->ten_dang_nhap)
                : null),
        ];
    }
}
