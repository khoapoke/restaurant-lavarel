<?php

namespace App\Http\Resources\Staff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckInQueueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'visitId' => (int) $this->ma_luot,
            'tableId' => (int) $this->ma_ban,
            'tableName' => $this->diningTable?->ten_ban,
            'location' => $this->diningTable?->vi_tri,
            'tableStatus' => $this->diningTable?->trang_thai?->name,
            'guestName' => $this->ten_khach,
            'guestCount' => $this->so_khach,
            'status' => $this->trang_thai->name,
            'requestedAt' => $this->tao_luc?->toIso8601String(),
            'expiresAt' => $this->tao_luc?->copy()->addMinutes((int) config('dining.check_in_expires_minutes', 15))->toIso8601String(),
        ];
    }
}
