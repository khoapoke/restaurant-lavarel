# 🛒 ĐẶC TẢ API MODULE: CART (GIỎ HÀNG KHÁCH HÀNG)

> **Namespace dự kiến:** `App\Modules\Cart\`  
> **Controller:** `Customer\CartController`  
> **Models:** `Cart` (`GIO_HANG`), `CartDish` (`MON_AN_GIO_HANG`)  
> **Ràng buộc DB:** Bảng pivot sử dụng khóa chính phức hợp `(ma_gio_hang, ma_mon_an)`.

---

## 1. Xem chi tiết giỏ hàng (`CRT-01`)
- **Method & URL:** `GET /api/v1/customer/cart`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Cart retrieved successfully",
  "data": {
    "cartId": 1,
    "accountId": 3,
    "items": [
      {
        "dishId": 1,
        "dishName": "Súp bào ngư",
        "imageUrl": "sup_bao_ngu.jpg",
        "unitPrice": 150000.00,
        "quantity": 2,
        "subTotal": 300000.00,
        "isAvailable": true
      },
      {
        "dishId": 2,
        "dishName": "Bò sốt tiêu đen",
        "imageUrl": "bo_tieu_den.jpg",
        "unitPrice": 350000.00,
        "quantity": 1,
        "subTotal": 350000.00,
        "isAvailable": true
      }
    ],
    "totalQuantity": 3,
    "estimatedTotalAmount": 650000.00
  }
}
```

---

## 2. Thêm món vào giỏ hàng (`CRT-02`)
- **Method & URL:** `POST /api/v1/customer/cart/items`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Request Body:**
```json
{
  "dishId": 1,
  "quantity": 2,
  "selectedOptions": "Chén tiêu chuẩn",
  "itemNote": "Không hành lá"
}
```
- **Response JSON Sample (Success - 200 OK):**
Trả về toàn bộ giỏ hàng cập nhật giống cấu trúc mục 1.
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Dish is out of stock or quantity is invalid",
  "errors": null
}
```
- **Ghi chú kỹ thuật:** Sử dụng `$cart->dishes()->updateExistingPivot(...)` hoặc `$cart->dishes()->attach(...)`.

---

## 3. Cập nhật số lượng món ăn trong giỏ (`CRT-03`)
- **Method & URL:** `PUT /api/v1/customer/cart/items/{dishId}`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Request Body:**
```json
{
  "quantity": 3
}
```
- **Response JSON Sample (Success - 200 OK):**
Trả về giỏ hàng cập nhật. Nếu `quantity = 0`, tự động loại bỏ món khỏi giỏ hàng.

---

## 4. Xóa món ra khỏi giỏ (`CRT-04`)
- **Method & URL:** `DELETE /api/v1/customer/cart/items/{dishId}`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Ghi chú:** Thực thi `$cart->dishes()->detach($dishId)`.

---

## 5. Làm rỗng giỏ hàng (`CRT-05`)
- **Method & URL:** `DELETE /api/v1/customer/cart/clear`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Cart cleared successfully",
  "data": {
    "cartId": 1,
    "accountId": 3,
    "items": [],
    "totalQuantity": 0,
    "estimatedTotalAmount": 0.00
  }
}
```

