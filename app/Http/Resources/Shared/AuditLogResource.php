<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Một dòng nhật ký thao tác (chỉ đọc). */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'logId' => (int) $this->ma_nhat_ky,
            'action' => $this->hanh_dong,
            'entity' => $this->doi_tuong,
            'entityId' => (int) $this->ma_doi_tuong,
            'reason' => $this->ly_do,
            'before' => $this->truoc,
            'after' => $this->sau,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->displayName() ?? 'Hệ thống'),
            'actorRole' => $this->vai_tro,
            'createdAt' => $this->tao_luc?->toIso8601String(),
        ];
    }
}
