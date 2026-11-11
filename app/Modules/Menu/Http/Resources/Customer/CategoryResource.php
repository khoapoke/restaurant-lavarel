<?php

namespace App\Modules\Menu\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'categoryId' => (int) $this->categoryId,
            'categoryName' => $this->categoryName,
            'description' => $this->description,
            'displayOrder' => (int) $this->displayOrder,
        ];
    }
}

