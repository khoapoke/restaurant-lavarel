<?php

namespace App\Http\Resources\Customer;

use App\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusName = $this->status instanceof ReservationStatus
            ? $this->status->name
            : (ReservationStatus::tryFrom($this->status)?->name ?? (string) $this->status);

        $reservationTime = null;
        if ($this->thoi_gian) {
            $reservationTime = is_string($this->thoi_gian)
                ? $this->thoi_gian
                : $this->thoi_gian->format('Y-m-d\TH:i:s');
        }

        $table = $this->relationLoaded('diningTable') ? $this->diningTable : $this->diningTable()->first();

        return [
            'reservationId' => (int) $this->reservationId,
            'reservationTime' => $reservationTime,
            'guestCount' => (int) $this->guestCount,
            'status' => $statusName,
            'note' => $this->note,
            'table' => $table ? new TableResource($table) : null,
        ];
    }
}
