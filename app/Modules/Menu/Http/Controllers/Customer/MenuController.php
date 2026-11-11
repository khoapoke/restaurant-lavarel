<?php

namespace App\Modules\Menu\Http\Controllers\Customer;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Menu\Http\Requests\Customer\WeatherRecommendationRequest;
use App\Modules\Menu\Http\Resources\Customer\CategoryResource;
use App\Modules\Menu\Http\Resources\Customer\DishDetailResource;
use App\Modules\Menu\Http\Resources\Customer\DishResource;
use App\Modules\Menu\Services\Customer\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends BaseController
{

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

    /**
     * Get dish detail by ID (MNU-07).
     *
     * @param int $dishId
     * @return JsonResponse
     */
    public function dishDetail(int $dishId): JsonResponse
    {
        $dish = $this->menuService->getDishDetail($dishId);

        return $this->successResponse(
            new DishDetailResource($dish),
            'Dish detail fetched successfully',
            200
        );
    }

    /**
     * Get weather-based recommendations (MNU-04).
     *
     * @param WeatherRecommendationRequest $request
     * @return JsonResponse
     */
    public function weatherRecommendations(WeatherRecommendationRequest $request): JsonResponse
    {
        $user = $request->user('sanctum') ?? $request->user();
        if ($user && !$user->relationLoaded('userProfile')) {
            $user->load('userProfile');
        }

        $recommendations = $this->menuService->getWeatherRecommendations(
            (float) $request->input('lat'),
            (float) $request->input('lon'),
            $user
        );

        return $this->successResponse(
            $recommendations,
            'Weather recommendations generated successfully',
            200
        );
    }
}


