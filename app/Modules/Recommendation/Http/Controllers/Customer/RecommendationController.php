<?php

namespace App\Modules\Recommendation\Http\Controllers\Customer;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Recommendation\Services\DishRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecommendationController extends BaseController
{
    protected DishRecommendationService $recommendationService;

    public function __construct(DishRecommendationService $recommendationService)
    {
        $this->recommendationService = $recommendationService;
    }

    /**
     * Get dishes frequently paired with a specific dish from Neo4j graph.
     *
     * @param int $dishId
     * @param Request $request
     * @return JsonResponse
     */
    public function pairedDishes(int $dishId, Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 4);
        if ($limit < 1 || $limit > 20) {
            $limit = 4;
        }

        $items = $this->recommendationService->getFrequentlyPairedDishes($dishId, $limit);

        return $this->successResponse(
            $items,
            'Graph recommendations retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Get personalized recommendations for authenticated customer using Collaborative Filtering.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function personalized(Request $request): JsonResponse
    {
        $accountId = (int) $request->user()->ma_tai_khoan;
        $limit = (int) $request->input('limit', 5);
        if ($limit < 1 || $limit > 20) {
            $limit = 5;
        }

        $items = $this->recommendationService->getPersonalizedRecommendations($accountId, $limit);

        return $this->successResponse(
            $items,
            'Personalized recommendations generated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Get real-time graph node and relationship statistics.
     *
     * @return JsonResponse
     */
    public function graphStats(): JsonResponse
    {
        $stats = $this->recommendationService->getGraphStats();

        return $this->successResponse(
            $stats,
            'Graph database statistics retrieved successfully',
            Response::HTTP_OK
        );
    }
}

