<?php

namespace App\Http\Resources\Admin;

use App\Enums\AccountStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusName = $this->status instanceof AccountStatus
            ? $this->status->name
            : (AccountStatus::tryFrom($this->status)?->name ?? ($this->status === 'Bị khóa' ? 'LOCKED' : 'ACTIVE'));

        return [
            'accountId' => $this->accountId,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $statusName,
            'roleName' => $this->role?->ten_vai_tro ?? 'Khách hàng',
            'fullName' => $this->userProfile?->fullName,
            'phoneNumber' => $this->userProfile?->phoneNumber,
        ];
    }
}
