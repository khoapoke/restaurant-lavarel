<?php

namespace App\Http\Resources\Customer;

use App\Enums\TableStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusName = $this->status instanceof TableStatus
            ? $this->status->name
            : (TableStatus::tryFrom($this->status)?->name ?? (string) $this->status);

        return [
            'tableId' => (int) $this->tableId,
            'tableName' => $this->tableName,
            'capacity' => (int) $this->capacity,
            'status' => $statusName,
            'location' => $this->location,
        ];
    }
}
