<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AddPosOrderItemRequest;
use App\Http\Requests\Staff\CreatePosOrderRequest;
use App\Http\Requests\Staff\SendKitchenRequest;
use App\Http\Requests\Staff\UpdatePosOrderItemRequest;
use App\Http\Resources\Staff\PosOrderItemResource;
use App\Http\Resources\Staff\PosOrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceSession;
use App\Services\Staff\PosOrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PosOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PosOrderService $posOrderService) {}

    public function store(CreatePosOrderRequest $request, ServiceSession $session): JsonResponse
    {
        $order = $this->posOrderService->createDraft($session, $request->user(), $request->validated());

        return $this->successResponse(new PosOrderResource($order), 'POS draft order ready', 201);
    }

    public function addItem(AddPosOrderItemRequest $request, Order $order): JsonResponse
    {
        $item = $this->posOrderService->addItem($order, $request->validated());

        return $this->successResponse(new PosOrderItemResource($item), 'Dish added to POS order', 201);
    }

    public function updateItem(UpdatePosOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        $updated = $this->posOrderService->updateItem($item, $request->validated());

        return $this->successResponse(new PosOrderItemResource($updated), 'POS order item updated');
    }

    public function destroyItem(OrderItem $item): JsonResponse
    {
        $this->posOrderService->deleteItem($item);

        return $this->successResponse(null, 'POS order item removed');
    }

    public function sendKitchen(SendKitchenRequest $request, Order $order): JsonResponse
    {
        $result = $this->posOrderService->sendKitchen($order, $request->user(), $request->validated('itemIds'));

        return $this->successResponse([
            'ticketId' => (int) $result['ticket']->ma_phieu_bep,
            'sentAt' => $result['ticket']->gui_luc?->toIso8601String(),
            'order' => new PosOrderResource($result['order']),
        ], 'Items sent to kitchen');
    }
}
