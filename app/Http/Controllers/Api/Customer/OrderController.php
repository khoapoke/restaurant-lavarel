<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelOrderRequest;
use App\Http\Requests\Customer\CheckoutOrderRequest;
use App\Http\Resources\Customer\OrderResource;
use App\Services\Customer\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    use ApiResponse;

    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Get customer order history (ORD-04).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $orders = $this->orderService->getCustomerOrders((int) $account->ma_tai_khoan, $request->all());

        return $this->successResponse([
            'items' => OrderResource::collection($orders->items()),
            'pagination' => [
                'currentPage' => $orders->currentPage(),
                'perPage' => $orders->perPage(),
                'totalItems' => $orders->total(),
                'totalPages' => $orders->lastPage(),
            ],
        ], 'Orders retrieved successfully', 200);
    }

    /**
     * Get order details by ID (ORD-05).
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function show(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $order = $this->orderService->getCustomerOrderDetail((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse(
            new OrderResource($order),
            'Order details retrieved successfully',
            200
        );
    }

    /**
     * Checkout active cart to create order (ORD-01, ORD-02, ORD-03).
     *
     * @param CheckoutOrderRequest $request
     * @return JsonResponse
     */
    public function checkout(CheckoutOrderRequest $request): JsonResponse
    {
        $account = $request->user();
        $order = $this->orderService->checkout((int) $account->ma_tai_khoan, $request->validated());

        return $this->successResponse(
            new OrderResource($order),
            'Order created successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Customer cancels order (ORD-06).
     *
     * @param CancelOrderRequest $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function cancel(CancelOrderRequest $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $cancelReason = $request->input('cancelReason');
        $order = $this->orderService->cancelCustomerOrder((int) $account->ma_tai_khoan, $orderId, $cancelReason);

        return $this->successResponse([
            'orderId' => (int) $order->orderId,
            'status' => 'CANCELLED',
        ], 'Order cancelled successfully', 200);
    }
}
