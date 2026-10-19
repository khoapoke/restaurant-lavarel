<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDishResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dishId' => (int) $this->dishId,
            'dishName' => $this->dishName,
            'price' => (float) $this->price,
            'description' => $this->description,
            'imageUrl' => $this->imageUrl,
            'categoryId' => (int) $this->categoryId,
            'categoryName' => $this->category?->categoryName ?? '',
            'ingredients' => $this->ingredients ?? [],
            'allergens' => $this->allergens ?? [],
            'weatherTags' => $this->weatherTags ?? [],
            'isAvailable' => (bool) $this->isAvailable,
            'isVisible' => (bool) $this->isVisible,
            'isDeleted' => (bool) $this->isDeleted,
        ];
    }
}
