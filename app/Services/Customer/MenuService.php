<?php

namespace App\Services\Customer;

use App\Models\Category;
use App\Models\Dish;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MenuService
{
    /**
     * Get active and visible categories list (MNU-01).
     *
     * @return Collection
     */
    public function getCategories(): Collection
    {
        return Category::where('da_xoa', false)
            ->where('hien_thi', true)
            ->orderBy('thu_tu_hien_thi', 'asc')
            ->orderBy('ma_danh_muc', 'asc')
            ->get();
    }

    /**
     * Get paginated dishes list with filters (MNU-02, MNU-03).
     *
     * @param array $filters
     * @return LengthAwarePaginator
     * @throws HttpException
     */
    public function getDishes(array $filters = []): LengthAwarePaginator
    {
        // Kiểm tra hợp lệ khoảng giá: minPrice không được lớn hơn maxPrice
        $hasMinPrice = isset($filters['minPrice']) && is_numeric($filters['minPrice']);
        $hasMaxPrice = isset($filters['maxPrice']) && is_numeric($filters['maxPrice']);

        if ($hasMinPrice && $hasMaxPrice && (float) $filters['minPrice'] > (float) $filters['maxPrice']) {
            throw new HttpException(400, 'minPrice cannot be greater than maxPrice');
        }

        $query = Dish::with('category')
            ->where('da_xoa', false)
            ->where('hien_thi', true);

        // 1. Tìm kiếm theo keyword trong tên món ăn (ten_mon_an)
        if (!empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where('ten_mon_an', 'like', "%{$keyword}%");
        }

        // 2. Lọc theo danh mục (categoryId)
        if (!empty($filters['categoryId']) && is_numeric($filters['categoryId'])) {
            $query->where('ma_danh_muc', (int) $filters['categoryId']);
        }

        // 3. Lọc theo khoảng giá (minPrice, maxPrice)
        if ($hasMinPrice) {
            $query->where('gia_tien', '>=', (float) $filters['minPrice']);
        }
        if ($hasMaxPrice) {
            $query->where('gia_tien', '<=', (float) $filters['maxPrice']);
        }

        // 4. Lọc theo tình trạng còn bán (isAvailable)
        if (isset($filters['isAvailable'])) {
            $isAvailable = filter_var($filters['isAvailable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isAvailable !== null) {
                $query->where('con_ban', $isAvailable);
            }
        }

        // 5. Loại bỏ món chứa chất dị ứng (excludeAllergens: danh sách cách nhau bởi dấu phẩy)
        if (!empty($filters['excludeAllergens'])) {
            $allergensList = explode(',', $filters['excludeAllergens']);
            foreach ($allergensList as $allergen) {
                $allergen = strtoupper(trim($allergen));
                if (!empty($allergen)) {
                    $query->where(function (Builder $q) use ($allergen) {
                        $q->whereNull('chat_di_ung')
                            ->orWhereJsonDoesntContain('chat_di_ung', $allergen);
                    });
                }
            }
        }

        $perPage = (int) ($filters['perPage'] ?? 12);
        $page = (int) ($filters['page'] ?? 1);

        return $query->orderBy('ma_mon_an', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
