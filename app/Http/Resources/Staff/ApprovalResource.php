<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'approvalId' => (int) $this->ma_yeu_cau_duyet,
            'type' => $this->loai->name,
            'label' => $this->loai->label(),
            'status' => $this->trang_thai->name,
            'entity' => $this->doi_tuong,
            'targetId' => (int) $this->ma_doi_tuong,
            'sessionId' => $this->ma_phien ? (int) $this->ma_phien : null,
            'tableNames' => $this->whenLoaded('serviceSession', fn () => $this->serviceSession?->tables?->pluck('ten_ban')->values() ?? []),
            'payload' => $this->du_lieu,
            'reason' => $this->ly_do,
            'requestedBy' => $this->whenLoaded('requester', fn () => $this->requester?->displayName()),
            'requestedById' => (int) $this->ma_nguoi_yeu_cau,
            'decidedBy' => $this->whenLoaded('approver', fn () => $this->approver?->displayName()),
            'decisionNote' => $this->ghi_chu_duyet,
            'createdAt' => $this->tao_luc?->toIso8601String(),
            'decidedAt' => $this->xu_ly_luc?->toIso8601String(),
        ];
    }
}
