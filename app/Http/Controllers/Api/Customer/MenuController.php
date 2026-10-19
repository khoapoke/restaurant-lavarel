<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CategoryResource;
use App\Http\Resources\Customer\DishResource;
use App\Services\Customer\MenuService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    use ApiResponse;

    protected MenuService $menuService;

    public function __construct(MenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    /**
     * Get active and visible categories list (MNU-01).
     *
     * @return JsonResponse
     */
    public function categories(): JsonResponse
    {
        $categories = $this->menuService->getCategories();

        return $this->successResponse(
            CategoryResource::collection($categories),
            'Categories retrieved successfully',
            200
        );
    }

    /**
     * Get paginated dishes list with filters (MNU-02, MNU-03).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function dishes(Request $request): JsonResponse
    {
        $dishes = $this->menuService->getDishes($request->all());

        return $this->successResponse([
            'items' => DishResource::collection($dishes->items()),
            'pagination' => [
                'currentPage' => $dishes->currentPage(),
                'perPage' => $dishes->perPage(),
                'totalItems' => $dishes->total(),
                'lastPage' => $dishes->lastPage(),
            ],
        ], 'Dishes retrieved successfully', 200);
    }
}
