<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CheckoutOrderRequest;
use App\Http\Resources\Customer\OrderResource;
use App\Services\Customer\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
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
}
