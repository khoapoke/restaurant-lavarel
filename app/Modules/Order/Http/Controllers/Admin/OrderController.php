<?php

namespace App\Modules\Order\Http\Controllers\Admin;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Order\Http\Requests\Admin\AdminCancelOrderRequest;
use App\Modules\Order\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Modules\Order\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Order\Services\Admin\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends BaseController
{

    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Get paginated orders list with search and filters (ORD-07).
     *
     * @param Request $request
     * @return JsonResponse
     */
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
        ], 'Orders retrieved successfully', 200);
    }

    /**
     * Get order details by ID for Admin.
     *
     * @param int $orderId
     * @return JsonResponse
     */
    public function show(int $orderId): JsonResponse
    {
        $order = $this->orderService->getOrderDetail($orderId);

        return $this->successResponse(
            new AdminOrderResource($order),
            'Order details retrieved successfully',
            200
        );
    }

    /**
     * Update order status sequentially according to State Machine (ORD-08 -> ORD-11).
     *
     * @param UpdateOrderStatusRequest $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function updateStatus(UpdateOrderStatusRequest $request, int $orderId): JsonResponse
    {
        $targetStatus = $request->input('targetStatus');
        $result = $this->orderService->updateStatus($orderId, $targetStatus);

        return $this->successResponse(
            $result,
            'Order status updated successfully',
            200
        );
    }

    /**
     * Admin cancels order with optional reason (ORD-12).
     *
     * @param AdminCancelOrderRequest $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function cancel(AdminCancelOrderRequest $request, int $orderId): JsonResponse
    {
        $cancelReason = $request->input('cancelReason');
        $result = $this->orderService->cancelOrder($orderId, $cancelReason);

        return $this->successResponse(
            $result,
            'Order cancelled successfully',
            200
        );
    }

    /**
     * Staff/Admin confirms payment received for order (ORD-13).
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function confirmPayment(Request $request, int $orderId): JsonResponse
    {
        $note = $request->input('note');
        $staffId = $request->user() ? (int) $request->user()->ma_tai_khoan : null;
        $result = $this->orderService->confirmPayment($orderId, $note, $staffId);

        return $this->successResponse(
            $result,
            'Order payment confirmed successfully by staff',
            200
        );
    }
}


