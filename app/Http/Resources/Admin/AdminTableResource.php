<?php

namespace App\Http\Resources\Admin;

use App\Enums\TableStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminTableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->trang_thai instanceof TableStatus
            ? $this->trang_thai->name
            : (TableStatus::tryFrom((string) $this->trang_thai)?->name ?? (string) $this->trang_thai);

        return [
            'tableId' => (int) $this->ma_ban,
            'tableName' => $this->ten_ban,
            'capacity' => (int) $this->suc_chua,
            'status' => $status,
            'location' => $this->vi_tri,
            'reservationsCount' => (int) ($this->reservations_count ?? 0),
        ];
    }
}
