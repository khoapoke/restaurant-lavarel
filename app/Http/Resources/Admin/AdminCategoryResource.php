<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin.
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
            'isVisible' => (bool) $this->isVisible,
            'isDeleted' => (bool) $this->isDeleted,
            'dishesCount' => $this->whenCounted('dishes'),
        ];
    }
}
