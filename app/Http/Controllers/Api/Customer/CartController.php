<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddCartItemRequest;
use App\Http\Requests\Customer\UpdateCartItemRequest;
use App\Http\Resources\Customer\CartResource;
use App\Services\Customer\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    use ApiResponse;

    protected CartService $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * View cart (CRT-04).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCart((int) $request->user()->ma_tai_khoan);

        return $this->successResponse(
            new CartResource($cart),
            'Cart retrieved successfully',
            200
        );
    }

    /**
     * Add dish to cart (CRT-01, MNU-08).
     *
     * @param AddCartItemRequest $request
     * @return JsonResponse
     */
    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->cartService->addItem(
            (int) $request->user()->ma_tai_khoan,
            (int) $request->input('dishId'),
            (int) $request->input('quantity')
        );

        return $this->successResponse(
            new CartResource($cart),
            'Cart updated successfully',
            200
        );
    }

    /**
     * Update dish quantity in cart (CRT-02).
     *
     * @param UpdateCartItemRequest $request
     * @param int $dishId
     * @return JsonResponse
     */
    public function updateQuantity(UpdateCartItemRequest $request, int $dishId): JsonResponse
    {
        $cart = $this->cartService->updateQuantity(
            (int) $request->user()->ma_tai_khoan,
            $dishId,
            (int) $request->input('quantity')
        );

        return $this->successResponse(
            new CartResource($cart),
            'Cart updated successfully',
            200
        );
    }

    /**
     * Remove dish from cart (CRT-03).
     *
     * @param Request $request
     * @param int $dishId
     * @return JsonResponse
     */
    public function removeItem(Request $request, int $dishId): JsonResponse
    {
        $cart = $this->cartService->removeItem(
            (int) $request->user()->ma_tai_khoan,
            $dishId
        );

        return $this->successResponse(
            new CartResource($cart),
            'Cart updated successfully',
            200
        );
    }
}
