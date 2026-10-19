<?php

namespace App\Services\Admin;

use App\Models\Dish;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DishService
{
    /**
     * Get paginated dishes list with filters for Admin.
     *
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getDishes(array $filters = []): LengthAwarePaginator
    {
        $query = Dish::with('category')->where('da_xoa', false);

        if (!empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where('ten_mon_an', 'like', "%{$keyword}%");
        }

        if (!empty($filters['categoryId']) && is_numeric($filters['categoryId'])) {
            $query->where('ma_danh_muc', (int) $filters['categoryId']);
        }

        if (isset($filters['isAvailable'])) {
            $isAvailable = filter_var($filters['isAvailable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isAvailable !== null) {
                $query->where('con_ban', $isAvailable);
            }
        }

        if (isset($filters['isVisible'])) {
            $isVisible = filter_var($filters['isVisible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isVisible !== null) {
                $query->where('hien_thi', $isVisible);
            }
        }

        if (isset($filters['minPrice']) && is_numeric($filters['minPrice'])) {
            $query->where('gia_tien', '>=', (float) $filters['minPrice']);
        }

        if (isset($filters['maxPrice']) && is_numeric($filters['maxPrice'])) {
            $query->where('gia_tien', '<=', (float) $filters['maxPrice']);
        }

        $perPage = (int) ($filters['perPage'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);

        return $query->orderBy('ma_mon_an', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get dish detail by ID.
     *
     * @param int $dishId
     * @return Dish
     * @throws HttpException
     */
    public function getDishDetail(int $dishId): Dish
    {
        $dish = Dish::with('category')->where('da_xoa', false)->find($dishId);

        if (!$dish) {
            throw new HttpException(404, 'Dish not found');
        }

        return $dish;
    }

    /**
     * Create a new dish (MNU-13).
     *
     * @param array $data
     * @return Dish
     */
    public function createDish(array $data): Dish
    {
        $dish = Dish::create([
            'ten_mon_an' => trim($data['dishName']),
            'gia_tien' => (float) $data['price'],
            'mo_ta' => $data['description'] ?? null,
            'hinh_anh' => $data['imageUrl'] ?? null,
            'ma_danh_muc' => (int) $data['categoryId'],
            'nguyen_lieu' => $data['ingredients'] ?? [],
            'chat_di_ung' => $data['allergens'] ?? [],
            'the_thoi_tiet' => $data['weatherTags'] ?? [],
            'con_ban' => isset($data['isAvailable']) ? (bool) $data['isAvailable'] : true,
            'hien_thi' => isset($data['isVisible']) ? (bool) $data['isVisible'] : true,
            'da_xoa' => false,
        ]);

        $dish->load('category');

        return $dish;
    }

    /**
     * Update an existing dish (MNU-14).
     *
     * @param int $dishId
     * @param array $data
     * @return Dish
     * @throws HttpException
     */
    public function updateDish(int $dishId, array $data): Dish
    {
        $dish = $this->getDishDetail($dishId);

        if (isset($data['dishName'])) {
            $dish->ten_mon_an = trim($data['dishName']);
        }
        if (isset($data['price'])) {
            $dish->gia_tien = $data['price'];
        }
        if (array_key_exists('description', $data)) {
            $dish->mo_ta = $data['description'];
        }
        if (array_key_exists('imageUrl', $data)) {
            $dish->hinh_anh = $data['imageUrl'];
        }
        if (isset($data['categoryId'])) {
            $dish->ma_danh_muc = (int) $data['categoryId'];
        }
        if (isset($data['ingredients'])) {
            $dish->nguyen_lieu = $data['ingredients'];
        }
        if (isset($data['allergens'])) {
            $dish->chat_di_ung = $data['allergens'];
        }
        if (isset($data['weatherTags'])) {
            $dish->the_thoi_tiet = $data['weatherTags'];
        }
        if (isset($data['isAvailable'])) {
            $dish->con_ban = (bool) $data['isAvailable'];
        }
        if (isset($data['isVisible'])) {
            $dish->hien_thi = (bool) $data['isVisible'];
        }

        $dish->save();
        $dish->load('category');

        return $dish;
    }

    /**
     * Update dish availability (on/off stock - MNU-15).
     *
     * @param int $dishId
     * @param bool|null $isAvailable
     * @return Dish
     * @throws HttpException
     */
    public function updateAvailability(int $dishId, ?bool $isAvailable = null): Dish
    {
        $dish = $this->getDishDetail($dishId);

        if ($isAvailable !== null) {
            $dish->con_ban = $isAvailable;
        } else {
            $dish->con_ban = !$dish->con_ban;
        }

        $dish->save();
        $dish->load('category');

        return $dish;
    }

    /**
     * Update dish visibility (hide/show - MNU-16).
     *
     * @param int $dishId
     * @param bool|null $isVisible
     * @return Dish
     * @throws HttpException
     */
    public function updateVisibility(int $dishId, ?bool $isVisible = null): Dish
    {
        $dish = $this->getDishDetail($dishId);

        if ($isVisible !== null) {
            $dish->hien_thi = $isVisible;
        } else {
            $dish->hien_thi = !$dish->hien_thi;
        }

        $dish->save();
        $dish->load('category');

        return $dish;
    }

    /**
     * Soft delete a dish (MNU-17).
     * Tuyệt đối không xóa vật lý vì sẽ vi phạm khóa ngoại trong CHI_TIET_DON_HANG.
     *
     * @param int $dishId
     * @return Dish
     * @throws HttpException
     */
    public function deleteDish(int $dishId): Dish
    {
        $dish = $this->getDishDetail($dishId);

        $dish->da_xoa = true;
        $dish->hien_thi = false;
        $dish->con_ban = false;
        $dish->save();

        $dish->load('category');

        return $dish;
    }
}
