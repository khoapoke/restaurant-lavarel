<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateCategoryRequest;
use App\Http\Requests\Admin\ToggleCategoryVisibilityRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\Admin\AdminCategoryResource;
use App\Services\Admin\CategoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    use ApiResponse;

    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Get list of categories for Admin.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $categories = $this->categoryService->getCategories();

        return $this->successResponse(
            AdminCategoryResource::collection($categories),
            'Categories retrieved successfully',
            200
        );
    }

    /**
     * Get single category detail.
     *
     * @param int $categoryId
     * @return JsonResponse
     */
    public function show(int $categoryId): JsonResponse
    {
        $category = $this->categoryService->getCategoryDetail($categoryId);

        return $this->successResponse(
            new AdminCategoryResource($category),
            'Category detail fetched successfully',
            200
        );
    }

    /**
     * Create a new category (MNU-09).
     *
     * @param CreateCategoryRequest $request
     * @return JsonResponse
     */
    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());

        return $this->successResponse(
            new AdminCategoryResource($category),
            'Category created successfully',
            201
        );
    }

    /**
     * Update an existing category (MNU-10).
     *
     * @param UpdateCategoryRequest $request
     * @param int $categoryId
     * @return JsonResponse
     */
    public function update(UpdateCategoryRequest $request, int $categoryId): JsonResponse
    {
        $category = $this->categoryService->updateCategory($categoryId, $request->validated());

        return $this->successResponse(
            new AdminCategoryResource($category),
            'Category updated successfully',
            200
        );
    }

    /**
     * Update category visibility (MNU-11).
     *
     * @param ToggleCategoryVisibilityRequest $request
     * @param int $categoryId
     * @return JsonResponse
     */
    public function updateVisibility(ToggleCategoryVisibilityRequest $request, int $categoryId): JsonResponse
    {
        $isVisible = $request->has('isVisible') ? $request->boolean('isVisible') : null;
        $category = $this->categoryService->updateVisibility($categoryId, $isVisible);

        return $this->successResponse(
            new AdminCategoryResource($category),
            'Category visibility updated successfully',
            200
        );
    }

    /**
     * Soft delete a category (MNU-12).
     *
     * @param int $categoryId
     * @return JsonResponse
     */
    public function destroy(int $categoryId): JsonResponse
    {
        $category = $this->categoryService->deleteCategory($categoryId);

        return $this->successResponse(
            new AdminCategoryResource($category),
            'Category deleted successfully',
            200
        );
    }
}
