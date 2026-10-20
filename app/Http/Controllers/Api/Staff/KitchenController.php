<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\KitchenItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateKitchenItemStatusRequest;
use App\Http\Resources\Staff\KitchenItemResource;
use App\Models\OrderItem;
use App\Services\Staff\KitchenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class KitchenController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KitchenService $kitchenService) {}

    public function index(): JsonResponse
    {
        return $this->successResponse([
            'syncedAt' => now()->toIso8601String(),
            'items' => KitchenItemResource::collection($this->kitchenService->activeItems()),
        ], 'Kitchen board retrieved successfully');
    }

    public function updateStatus(UpdateKitchenItemStatusRequest $request, OrderItem $item): JsonResponse
    {
        $updated = $this->kitchenService->updateStatus(
            $item,
            KitchenItemStatus::from($request->validated('status'))
        );

        return $this->successResponse(new KitchenItemResource($updated), 'Kitchen item status updated');
    }
}
