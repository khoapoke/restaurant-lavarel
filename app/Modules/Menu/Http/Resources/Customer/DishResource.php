<?php

namespace App\Modules\Menu\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DishResource extends JsonResource
{
    /**
     * Transform the resource into an array.
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
            'isAvailable' => (bool) $this->isAvailable,
            'ingredients' => $this->ingredients ?? [],
            'allergens' => $this->allergens ?? [],
        ];
    }
}

