<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelOrderRequest;
use App\Http\Requests\Admin\UpdateFulfillmentStatusRequest;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Services\Admin\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $orders = $this->orderService->getOrders($request->all());

        return $this->successResponse([
            'items' => AdminOrderResource::collection($orders->items()),
            'pagination' => [
                'currentPage' => $orders->currentPage(),
                'perPage' => $orders->perPage(),
                'totalItems' => $orders->total(),
                'totalPages' => $orders->lastPage(),
            ],
        ], 'Orders retrieved successfully');
    }

    public function show(int $orderId): JsonResponse
    {
        return $this->successResponse(new AdminOrderResource($this->orderService->getOrder($orderId)), 'Order retrieved successfully');
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $orderId): JsonResponse
    {
        $order = $this->orderService->updateStatus($orderId, $request->validated('targetStatus'));

        return $this->successResponse(new AdminOrderResource($order), 'Order status updated successfully');
    }

    public function updateFulfillmentStatus(UpdateFulfillmentStatusRequest $request, int $orderId): JsonResponse
    {
        $order = $this->orderService->updateFulfillmentStatus($orderId, $request->validated('targetStatus'));

        return $this->successResponse(new AdminOrderResource($order), 'Order fulfillment updated successfully');
    }

    public function cancel(CancelOrderRequest $request, int $orderId): JsonResponse
    {
        $order = $this->orderService->cancel($orderId, $request->validated('cancelReason'));

        return $this->successResponse(new AdminOrderResource($order), 'Order cancelled successfully');
    }
}
