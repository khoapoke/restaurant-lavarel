<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Dish;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoryService
{
    /**
     * Get all active categories with dish count for Admin.
     */
    public function getCategories(): Collection
    {
        return Category::withCount(['dishes' => function ($q) {
            $q->where('da_xoa', false);
        }])
            ->where('da_xoa', false)
            ->orderBy('thu_tu_hien_thi', 'asc')
            ->orderBy('ma_danh_muc', 'asc')
            ->get();
    }

    /**
     * Get category detail by ID.
     *
     * @throws HttpException
     */
    public function getCategoryDetail(int $categoryId): Category
    {
        $category = Category::where('da_xoa', false)->find($categoryId);

        if (! $category) {
            throw new HttpException(404, 'Category not found');
        }

        return $category;
    }

    /**
     * Create a new category (MNU-09).
     */
    public function createCategory(array $data): Category
    {
        $category = Category::create([
            'ten_danh_muc' => trim($data['categoryName']),
            'mo_ta' => $data['description'] ?? null,
            'thu_tu_hien_thi' => (int) ($data['displayOrder'] ?? 0),
            'hien_thi' => isset($data['isVisible']) ? (bool) $data['isVisible'] : true,
            'da_xoa' => false,
        ]);

        return $category;
    }

    /**
     * Update an existing category (MNU-10).
     *
     * @throws HttpException
     */
    public function updateCategory(int $categoryId, array $data): Category
    {
        $category = $this->getCategoryDetail($categoryId);

        if (isset($data['categoryName'])) {
            $category->ten_danh_muc = trim($data['categoryName']);
        }
        if (array_key_exists('description', $data)) {
            $category->mo_ta = $data['description'];
        }
        if (isset($data['displayOrder'])) {
            $category->thu_tu_hien_thi = (int) $data['displayOrder'];
        }
        if (isset($data['isVisible'])) {
            $category->hien_thi = (bool) $data['isVisible'];
        }

        $category->save();

        return $category;
    }

    /**
     * Update visibility (hide/show) of a category (MNU-11).
     *
     * @throws HttpException
     */
    public function updateVisibility(int $categoryId, ?bool $isVisible = null): Category
    {
        $category = $this->getCategoryDetail($categoryId);

        if ($isVisible !== null) {
            $category->hien_thi = $isVisible;
        } else {
            $category->hien_thi = ! $category->hien_thi;
        }

        $category->save();

        return $category;
    }

    /**
     * Soft delete a category (MNU-12).
     * Tuyệt đối không xóa vật lý để tránh vi phạm khóa ngoại trong MON_AN.
     *
     * @throws HttpException
     */
    public function deleteCategory(int $categoryId): Category
    {
        $category = $this->getCategoryDetail($categoryId);

        $category->da_xoa = true;
        $category->hien_thi = false;
        $category->save();

        // Món thuộc danh mục bị xóa tự ẩn khỏi thực đơn qua Dish::publiclyListed(); cờ của từng
        // món giữ nguyên để khôi phục danh mục không vô tình bật lại món đã tắt (MNU-01).

        return $category;
    }
}
