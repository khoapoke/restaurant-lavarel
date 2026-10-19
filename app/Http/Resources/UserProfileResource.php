<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'userId' => $this->userId,
            'fullName' => $this->fullName,
            'phoneNumber' => $this->phoneNumber,
            'birthDate' => $this->birthDate ? (is_string($this->birthDate) ? $this->birthDate : $this->birthDate->format('Y-m-d')) : null,
            'address' => $this->address,
        ];
    }
}
