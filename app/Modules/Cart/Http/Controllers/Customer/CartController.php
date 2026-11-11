<?php

namespace App\Modules\Cart\Http\Controllers\Customer;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Cart\Http\Requests\Customer\AddCartItemRequest;
use App\Modules\Cart\Http\Requests\Customer\UpdateCartItemRequest;
use App\Modules\Cart\Http\Resources\Customer\CartResource;
use App\Modules\Cart\Services\Customer\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends BaseController
{

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


