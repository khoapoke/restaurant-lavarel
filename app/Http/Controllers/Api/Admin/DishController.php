<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateDishRequest;
use App\Http\Requests\Admin\ToggleDishAvailabilityRequest;
use App\Http\Requests\Admin\ToggleDishVisibilityRequest;
use App\Http\Requests\Admin\UpdateDishRequest;
use App\Http\Resources\Admin\AdminDishResource;
use App\Services\Admin\DishService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DishController extends Controller
{
    use ApiResponse;

    protected DishService $dishService;

    public function __construct(DishService $dishService)
    {
        $this->dishService = $dishService;
    }

    /**
     * Get paginated dishes list with filters for Admin.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $dishes = $this->dishService->getDishes($request->all());

        return $this->successResponse([
            'items' => AdminDishResource::collection($dishes->items()),
            'pagination' => [
                'currentPage' => $dishes->currentPage(),
                'perPage' => $dishes->perPage(),
                'totalItems' => $dishes->total(),
                'lastPage' => $dishes->lastPage(),
            ],
        ], 'Dishes retrieved successfully', 200);
    }

    /**
     * Get dish detail by ID for Admin.
     *
     * @param int $dishId
     * @return JsonResponse
     */
    public function show(int $dishId): JsonResponse
    {
        $dish = $this->dishService->getDishDetail($dishId);

        return $this->successResponse(
            new AdminDishResource($dish),
            'Dish detail fetched successfully',
            200
        );
    }

    /**
     * Create a new dish (MNU-13).
     *
     * @param CreateDishRequest $request
     * @return JsonResponse
     */
    public function store(CreateDishRequest $request): JsonResponse
    {
        $dish = $this->dishService->createDish($request->validated());

        return $this->successResponse(
            new AdminDishResource($dish),
            'Dish saved successfully',
            201
        );
    }

    /**
     * Update an existing dish (MNU-14).
     *
     * @param UpdateDishRequest $request
     * @param int $dishId
     * @return JsonResponse
     */
    public function update(UpdateDishRequest $request, int $dishId): JsonResponse
    {
        $dish = $this->dishService->updateDish($dishId, $request->validated());

        return $this->successResponse(
            new AdminDishResource($dish),
            'Dish saved successfully',
            200
        );
    }

    /**
     * Toggle dish availability (on/off stock - MNU-15).
     *
     * @param ToggleDishAvailabilityRequest $request
     * @param int $dishId
     * @return JsonResponse
     */
    public function updateAvailability(ToggleDishAvailabilityRequest $request, int $dishId): JsonResponse
    {
        $isAvailable = $request->has('isAvailable') ? $request->boolean('isAvailable') : null;
        $dish = $this->dishService->updateAvailability($dishId, $isAvailable);

        return $this->successResponse(
            new AdminDishResource($dish),
            'Dish availability updated successfully',
            200
        );
    }

    /**
     * Toggle dish visibility (hide/show - MNU-16).
     *
     * @param ToggleDishVisibilityRequest $request
     * @param int $dishId
     * @return JsonResponse
     */
    public function updateVisibility(ToggleDishVisibilityRequest $request, int $dishId): JsonResponse
    {
        $isVisible = $request->has('isVisible') ? $request->boolean('isVisible') : null;
        $dish = $this->dishService->updateVisibility($dishId, $isVisible);

        return $this->successResponse(
            new AdminDishResource($dish),
            'Dish visibility updated successfully',
            200
        );
    }

    /**
     * Soft delete a dish (MNU-17).
     *
     * @param int $dishId
     * @return JsonResponse
     */
    public function destroy(int $dishId): JsonResponse
    {
        $dish = $this->dishService->deleteDish($dishId);

        return $this->successResponse(
            new AdminDishResource($dish),
            'Dish deleted successfully',
            200
        );
    }
}
