<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\RoleType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Map Vietnamese role name back to English enum constant name (e.g. 'Khách hàng' -> 'CUSTOMER')
        $roleName = 'CUSTOMER';
        if ($this->relationLoaded('role') && $this->role) {
            $matchedRole = RoleType::tryFrom($this->role->ten_vai_tro);
            $roleName = $matchedRole ? $matchedRole->name : $this->role->ten_vai_tro;
        }

        $statusName = $this->status instanceof AccountStatus
            ? $this->status->name
            : (AccountStatus::tryFrom($this->status)?->name ?? (string) $this->status);

        return [
            'accountId' => $this->accountId,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $statusName,
            'role' => $roleName,
            'userProfile' => new UserProfileResource($this->whenLoaded('userProfile')),
            'cartId' => $this->relationLoaded('cart') ? $this->cart?->ma_gio_hang : ($this->cart()->value('ma_gio_hang')),
        ];
    }
}

