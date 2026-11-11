<?php

namespace App\Modules\Auth\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->relationLoaded('userProfile') ? $this->userProfile : $this->userProfile()->first();

        // Chuẩn hóa danh sách địa chỉ đã lưu (savedAddresses)
        $rawSavedAddresses = $profile?->savedAddresses ?? [];
        $savedAddresses = [];
        foreach ($rawSavedAddresses as $index => $item) {
            if (is_array($item)) {
                $savedAddresses[] = [
                    'addressId' => $item['addressId'] ?? ('addr_' . ($index + 1)),
                    'recipientName' => $item['recipientName'] ?? ($profile?->fullName ?? ''),
                    'recipientPhone' => $item['recipientPhone'] ?? ($profile?->phoneNumber ?? ''),
                    'fullAddress' => $item['fullAddress'] ?? ($item['address'] ?? ''),
                    'isDefault' => (bool) ($item['isDefault'] ?? ($index === 0)),
                ];
            } else {
                $savedAddresses[] = [
                    'addressId' => 'addr_' . ($index + 1),
                    'recipientName' => $profile?->fullName ?? '',
                    'recipientPhone' => $profile?->phoneNumber ?? '',
                    'fullAddress' => (string) $item,
                    'isDefault' => ($index === 0),
                ];
            }
        }

        return [
            'accountId' => $this->accountId,
            'username' => $this->username,
            'email' => $this->email,
            'userId' => $profile?->userId,
            'fullName' => $profile?->fullName,
            'birthDate' => $profile?->birthDate ? (is_string($profile->birthDate) ? $profile->birthDate : $profile->birthDate->format('Y-m-d')) : null,
            'phoneNumber' => $profile?->phoneNumber,
            'defaultAddress' => $profile?->address,
            'savedAddresses' => $savedAddresses,
            'allergies' => $profile?->allergies ?? [],
            'dietaryPreferences' => $profile?->dietaryPreferences ?? [],
        ];
    }
}

