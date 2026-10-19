<?php

namespace App\Services\Customer;

use App\Models\Cart;
use App\Models\Dish;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CartService
{
    /**
     * Get or create active cart for the customer (CRT-04).
     *
     * @param int $accountId
     * @return Cart
     */
    public function getCart(int $accountId): Cart
    {
        $cart = Cart::with('dishes')->firstOrCreate(
            ['ma_tai_khoan' => $accountId],
            ['ngay_tao' => now()]
        );

        return $cart;
    }

    /**
     * Add dish to cart or accumulate quantity using BelongsToMany pivot (CRT-01, MNU-08).
     *
     * @param int $accountId
     * @param int $dishId
     * @param int $quantity
     * @return Cart
     * @throws HttpException
     */
    public function addItem(int $accountId, int $dishId, int $quantity): Cart
    {
        // 1. Kiểm tra món ăn có tồn tại và đang còn bán không
        $dish = Dish::where('ma_mon_an', $dishId)->first();
        if (!$dish || !$dish->isAvailable || !$dish->isVisible || $dish->isDeleted) {
            throw new HttpException(400, 'Dish is currently unavailable or out of stock');
        }

        // 2. Lấy hoặc tạo giỏ hàng
        $cart = Cart::firstOrCreate(
            ['ma_tai_khoan' => $accountId],
            ['ngay_tao' => now()]
        );

        // 3. Thao tác trên bảng trung gian MON_AN_GIO_HANG qua Eloquent Pivot
        $existing = $cart->dishes()->wherePivot('ma_mon_an', $dishId)->first();

        if ($existing) {
            $newQuantity = (int) $existing->pivot->so_luong + $quantity;
            $cart->dishes()->updateExistingPivot($dishId, [
                'so_luong' => $newQuantity,
            ]);
        } else {
            $cart->dishes()->attach($dishId, [
                'so_luong' => $quantity,
            ]);
        }

        // 4. Nạp lại quan hệ và trả về
        $cart->load('dishes');

        return $cart;
    }

    /**
     * Update quantity of a dish in cart (CRT-02).
     *
     * @param int $accountId
     * @param int $dishId
     * @param int $quantity
     * @return Cart
     */
    public function updateQuantity(int $accountId, int $dishId, int $quantity): Cart
    {
        $cart = Cart::firstOrCreate(
            ['ma_tai_khoan' => $accountId],
            ['ngay_tao' => now()]
        );

        if ($quantity <= 0) {
            $cart->dishes()->detach($dishId);
        } else {
            // Kiểm tra món đã có trong giỏ chưa
            $existing = $cart->dishes()->wherePivot('ma_mon_an', $dishId)->first();
            if ($existing) {
                $cart->dishes()->updateExistingPivot($dishId, [
                    'so_luong' => $quantity,
                ]);
            } else {
                $cart->dishes()->attach($dishId, [
                    'so_luong' => $quantity,
                ]);
            }
        }

        $cart->load('dishes');

        return $cart;
    }

    /**
     * Remove dish from cart (CRT-03).
     *
     * @param int $accountId
     * @param int $dishId
     * @return Cart
     */
    public function removeItem(int $accountId, int $dishId): Cart
    {
        $cart = Cart::firstOrCreate(
            ['ma_tai_khoan' => $accountId],
            ['ngay_tao' => now()]
        );

        $cart->dishes()->detach($dishId);
        $cart->load('dishes');

        return $cart;
    }
}
