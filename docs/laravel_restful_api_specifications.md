# TẦNG L2: ĐẶC TẢ CHI TIẾT LARAVEL RESTFUL API (API SPECIFICATIONS)

Tài liệu này định nghĩa toàn bộ các API Endpoints được xây dựng trên nền tảng **Laravel 10/11 + Laravel Sanctum**, phân tách độc lập thành hai phân hệ: **CUSTOMER APIs** và **ADMIN APIs**.

---

## QUY ƯỚC KIẾN TRÚC API TRONG LARAVEL (LARAVEL API CONVENTIONS)

1. **Định tuyến (`routes/api.php`):** Toàn bộ route có tiền tố `/api/v1`.
2. **Headers Bắt buộc từ Client (ReactJS / NextJS):**
   - `Accept: application/json` *(Cực kỳ quan trọng trong Laravel: Nếu thiếu header này, khi lỗi Validation hoặc lỗi 401, Laravel sẽ redirect về trang HTML `/login` thay vì trả về JSON!)*
   - `Content-Type: application/json`
   - `Authorization: Bearer <sanctum_plain_text_token>` (đối với các API yêu cầu đăng nhập).
3. **Middleware Pipeline:**
   - `auth:sanctum`: Xác thực Token và nạp `Account` hiện tại vào `$request->user()`.
   - `account.active`: Middleware tùy chỉnh kiểm tra `$request->user()->trang_thai === AccountStatus::ACTIVE`. Nếu bị khóa (`LOCKED`), hủy token và trả về `403 Forbidden`.
   - `role:CUSTOMER` / `role:ADMIN`: Kiểm tra `$request->user()->ma_vai_tro` (`3` = Customer, `1` = Admin).
4. **Chuẩn Mã Lỗi HTTP trong Laravel:**
   - `200 OK` / `201 Created`: Thành công.
   - `400 Bad Request`: Lỗi tham số hoặc điều kiện nghiệp vụ cơ bản.
   - `401 Unauthorized`: Chưa đăng nhập hoặc Token không hợp lệ / hết hạn.
   - `403 Forbidden`: Tài khoản bị khóa hoặc không đủ quyền (`Role`).
   - `404 Not Found`: Không tìm thấy bản ghi (`ModelNotFoundException`).
   - `409 Conflict`: Xung đột trạng thái nghiệp vụ (VD: Hủy đơn khi bếp đã nấu, đặt trùng giờ bàn).
   - `422 Unprocessable Entity`: Lỗi dữ liệu đầu vào từ `FormRequest` chuẩn của Laravel.

---

## PHẦN 0: SHARED AUTHENTICATION APIS (`AuthController`)

### 0.1. Đăng ký tài khoản Khách hàng (`USR-01`)
- **Method & URL:** `POST /api/v1/auth/register`
- **Controller & FormRequest:** `AuthController@register` | `RegisterRequest`
- **Request Body:**
```json
{
  "username": "khachhang_moi",
  "password": "Password@123",
  "passwordConfirmation": "Password@123",
  "email": "khachhang_moi@gmail.com",
  "fullName": "Nguyễn Minh Tuấn",
  "phoneNumber": "0987654321",
  "birthDate": "1999-08-15",
  "address": "140 Lê Trọng Tấn, Quận Tân Phú, TP.HCM"
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Account registered successfully",
  "data": {
    "accessToken": "1|laravel_sanctum_token_string_example_abc123",
    "tokenType": "Bearer",
    "account": {
      "accountId": 5,
      "username": "khachhang_moi",
      "email": "khachhang_moi@gmail.com",
      "status": "ACTIVE",
      "role": "CUSTOMER",
      "userProfile": {
        "userId": 5,
        "fullName": "Nguyễn Minh Tuấn",
        "phoneNumber": "0987654321",
        "birthDate": "1999-08-15",
        "address": "140 Lê Trọng Tấn, Quận Tân Phú, TP.HCM"
      },
      "cartId": 4
    }
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity - Chuẩn Laravel Validation):**
```json
{
  "success": false,
  "code": 422,
  "message": "The given data was invalid.",
  "errors": {
    "username": [
      "The username has already been taken."
    ],
    "phoneNumber": [
      "The phone number format is invalid."
    ]
  }
}
```
- **Ghi chú nghiệp vụ (Laravel):** Sử dụng Rule `unique:TAI_KHOAN,ten_dang_nhap` và `unique:TAI_KHOAN,email` trong `RegisterRequest`. Bọc trong `DB::transaction()` để tạo tuần tự: `UserProfile` (`NGUOI_DUNG`) $\rightarrow$ `Account` (`TAI_KHOAN` với `ma_vai_tro = 3`, `mat_khau = Hash::make($request->password)`) $\rightarrow$ `Cart` (`GIO_HANG`).

---

### 0.2. Đăng nhập hệ thống (`USR-02`)
- **Method & URL:** `POST /api/v1/auth/login`
- **Controller & FormRequest:** `AuthController@login` | `LoginRequest`
- **Request Body:**
```json
{
  "username": "kh1",
  "password": "123",
  "rememberMe": true
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Login successful",
  "data": {
    "accessToken": "2|sanctum_token_xyz987654321",
    "tokenType": "Bearer",
    "expiresAt": "2026-07-10T08:05:00Z",
    "account": {
      "accountId": 3,
      "username": "kh1",
      "email": "khachhang1@gmail.com",
      "role": "CUSTOMER",
      "status": "ACTIVE",
      "userProfile": {
        "userId": 3,
        "fullName": "Lê Hoàng Hùng",
        "phoneNumber": "0923456789",
        "address": "789 Cách Mạng Tháng 8"
      }
    }
  }
}
```
- **Response JSON Sample (Error - 401 Unauthorized / 403 Forbidden):**
```json
{
  "success": false,
  "code": 401,
  "message": "Invalid credentials or account has been locked",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Tìm `Account::where('ten_dang_nhap', $request->username)->first()`. Hỗ trợ tự động nâng cấp mật khẩu mẫu `'123'` sang `BCrypt` nếu DB vừa chạy script SQL mẫu. Cấp Sanctum Token qua `$account->createToken('auth_token', [$roleAbility], $expiresAt)->plainTextToken`.

---

### 0.3. Đăng xuất hệ thống
- **Method & URL:** `POST /api/v1/auth/logout`
- **Middleware:** `auth:sanctum`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Logged out successfully",
  "data": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Thực thi `$request->user()->currentAccessToken()->delete()` để hủy Token hiện tại.

---

## PHẦN A: CUSTOMER APIS (PHÂN HỆ KHÁCH HÀNG)

### A.1. Quản lý Hồ sơ, Địa chỉ & Dị ứng (`Customer\ProfileController`)

#### 1. Xem hồ sơ cá nhân (`USR-03`)
- **Method & URL:** `GET /api/v1/customer/profile`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Profile retrieved successfully",
  "data": {
    "accountId": 3,
    "username": "kh1",
    "email": "khachhang1@gmail.com",
    "userId": 3,
    "fullName": "Lê Hoàng Hùng",
    "birthDate": "1995-12-01",
    "phoneNumber": "0923456789",
    "defaultAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
    "savedAddresses": [
      {
        "addressId": "addr_1",
        "recipientName": "Lê Hoàng Hùng",
        "recipientPhone": "0923456789",
        "fullAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
        "isDefault": true
      }
    ],
    "allergies": ["PEANUT", "SHELLFISH"],
    "dietaryPreferences": ["LOW_SUGAR"]
  }
}
```
- **Response JSON Sample (Error - 401 Unauthorized):**
```json
{
  "success": false,
  "code": 401,
  "message": "Unauthenticated.",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Sử dụng `$request->user()->load('userProfile')` và trả về qua `CustomerProfileResource`.

#### 2. Cập nhật hồ sơ, địa chỉ nhận hàng & thông tin dị ứng (`USR-04`, `USR-05`, `USR-06`)
- **Method & URL:** `PUT /api/v1/customer/profile`
- **Request Body:**
```json
{
  "fullName": "Lê Hoàng Hùng",
  "birthDate": "1995-12-01",
  "phoneNumber": "0923456789",
  "email": "khachhang1_new@gmail.com",
  "defaultAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
  "savedAddresses": [
    {
      "addressId": "addr_1",
      "recipientName": "Lê Hoàng Hùng",
      "recipientPhone": "0923456789",
      "fullAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
      "isDefault": true
    }
  ],
  "allergies": ["PEANUT", "SHELLFISH"],
  "dietaryPreferences": ["HIGH_PROTEIN"]
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Profile updated successfully",
  "data": {
    "accountId": 3,
    "email": "khachhang1_new@gmail.com",
    "fullName": "Lê Hoàng Hùng",
    "phoneNumber": "0923456789",
    "defaultAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
    "allergies": ["PEANUT", "SHELLFISH"],
    "dietaryPreferences": ["HIGH_PROTEIN"]
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity):**
```json
{
  "success": false,
  "code": 422,
  "message": "Validation failed",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```
- **Ghi chú nghiệp vụ (Laravel):** Cập nhật bảng `TAI_KHOAN` (`email`) và bảng `NGUOI_DUNG` (`ho_ten`, `ngay_sinh`, `so_dien_thoai`, `dia_chi`, cùng các trường mảng JSON `allergies`, `dietaryPreferences`).

---

### A.2. Thực đơn, Tìm kiếm, Lọc & Gợi ý theo Thời tiết (`Customer\MenuController`)

#### 1. Xem danh sách danh mục & món ăn có bộ lọc (`MNU-01`, `MNU-02`, `MNU-03`)
- **Method & URL:**
  - Danh mục: `GET /api/v1/customer/categories`
  - Danh sách món ăn: `GET /api/v1/customer/dishes`
- **Query Params (`GET /api/v1/customer/dishes`):**
  - `keyword` (`string`, optional): Tìm kiếm theo `ten_mon_an`.
  - `categoryId` (`int`, optional): Lọc theo `ma_danh_muc`.
  - `minPrice` (`numeric`, optional), `maxPrice` (`numeric`, optional): Lọc theo khoảng `gia_tien`.
  - `isAvailable` (`boolean`, optional): Lọc theo tình trạng còn bán.
  - `excludeAllergens` (`string`, optional): Chuỗi chất dị ứng cách nhau dấu phẩy (VD: `SHELLFISH,PEANUT`).
  - `page` (`int`, default `1`), `perPage` (`int`, default `12`). *(Lưu ý: Phân trang mặc định của Laravel `paginate()` bắt đầu từ `page = 1`)*.
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dishes retrieved successfully",
  "data": {
    "items": [
      {
        "dishId": 2,
        "dishName": "Bò sốt tiêu đen",
        "price": 350000.00,
        "description": "Thịt bò Mỹ mềm ngon kèm sốt tiêu đen đậm đà",
        "imageUrl": "bo_tieu_den.jpg",
        "categoryId": 2,
        "categoryName": "Món chính",
        "isAvailable": true,
        "ingredients": ["Thịt bò Mỹ", "Tiêu đen", "Bơ lạt"],
        "allergens": ["DAIRY"]
      }
    ],
    "pagination": {
      "currentPage": 1,
      "perPage": 12,
      "totalItems": 1,
      "lastPage": 1
    }
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "minPrice cannot be greater than maxPrice",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Sử dụng Eloquent Local Scope `Dish::query()->active()->with('category')->when(...)` để lọc linh hoạt và loại bỏ các món có chứa `excludeAllergens`.

#### 2. Xem chi tiết món ăn (`MNU-07`)
- **Method & URL:** `GET /api/v1/customer/dishes/{dishId}`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dish detail fetched successfully",
  "data": {
    "dishId": 1,
    "dishName": "Súp bào ngư",
    "price": 150000.00,
    "description": "Súp bào ngư thượng hạng giàu dinh dưỡng",
    "imageUrl": "sup_bao_ngu.jpg",
    "category": {
      "categoryId": 1,
      "categoryName": "Khai vị"
    },
    "isAvailable": true,
    "ingredients": ["Bào ngư tươi", "Nấm đông cô", "Trứng gà"],
    "allergens": ["SHELLFISH", "EGG"]
  }
}
```
- **Response JSON Sample (Error - 404 Not Found):**
```json
{
  "success": false,
  "code": 404,
  "message": "Dish not found or unavailable",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Truy vấn `Dish::active()->with('category')->findOrFail($dishId)`.

#### 3. Gợi ý món ăn theo Thời tiết & Tự động loại trừ Dị ứng (Extended Feature)
- **Method & URL:** `GET /api/v1/customer/recommendations/weather`
- **Query Params:** `lat` (`numeric`, required), `lon` (`numeric`, required).
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Weather recommendations generated successfully",
  "data": {
    "weather": {
      "city": "Ho Chi Minh City",
      "temperatureCelsius": 33.8,
      "condition": "HOT_SUNNY",
      "advice": "Trời nắng nóng (33.8°C), ưu tiên các món thanh mát. Đã lọc bỏ món chứa chất gây dị ứng: SHELLFISH, PEANUT."
    },
    "excludedAllergens": ["SHELLFISH", "PEANUT"],
    "recommendedDishes": [
      {
        "dishId": 4,
        "dishName": "Trà đào cam sả",
        "price": 45000.00,
        "imageUrl": "tra_dao.jpg",
        "categoryName": "Đồ uống",
        "allergens": []
      }
    ]
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity):**
```json
{
  "success": false,
  "code": 422,
  "message": "Coordinates lat and lon are required",
  "errors": {
    "lat": ["The lat field is required."],
    "lon": ["The lon field is required."]
  }
}
```
- **Ghi chú nghiệp vụ (Laravel):** Dùng `Http::timeout(5)->get('https://api.openweathermap.org/data/2.5/weather', ...)` kết hợp `Cache::remember()` trong 15 phút theo tọa độ làm tròn để tối ưu tốc độ (< 3 giây) và loại bỏ 100% món chứa chất dị ứng của khách.

---

### A.3. Quản lý Giỏ hàng (`Customer\CartController`)

#### 1. Xem giỏ hàng, Thêm món, Cập nhật số lượng & Xóa món (`CRT-01` $\rightarrow$ `CRT-04`, `MNU-08`)
- **Method & URL:**
  - Xem giỏ hàng (`CRT-04`): `GET /api/v1/customer/cart`
  - Thêm món vào giỏ (`CRT-01`): `POST /api/v1/customer/cart/items`
  - Cập nhật số lượng (`CRT-02`): `PUT /api/v1/customer/cart/items/{dishId}`
  - Xóa món khỏi giỏ (`CRT-03`): `DELETE /api/v1/customer/cart/items/{dishId}`
- **Request Body (`POST /api/v1/customer/cart/items`):**
```json
{
  "dishId": 1,
  "quantity": 2,
  "selectedOptions": "Chén tiêu chuẩn",
  "itemNote": "Không hành lá"
}
```
- **Response JSON Sample (Success - 200 OK - Cấu trúc trả về đồng nhất cho mọi thao tác Giỏ hàng):**
```json
{
  "success": true,
  "code": 200,
  "message": "Cart updated successfully",
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
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Dish is out of stock or quantity is invalid",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel Pivot Handling):**
  - Vì bảng `MON_AN_GIO_HANG` dùng khóa chính phức hợp `(ma_gio_hang, ma_mon_an)`, Controller sử dụng quan hệ `BelongsToMany` (`$cart->dishes()`):
    - Khi thêm món: Kiểm tra nếu đã có trong giỏ thì gọi `$cart->dishes()->updateExistingPivot($dishId, ['so_luong' => $newQty])`, nếu chưa có thì gọi `$cart->dishes()->attach($dishId, ['so_luong' => $qty])`.
    - Khi xóa món: Gọi `$cart->dishes()->detach($dishId)`. Cách này không bao giờ bị lỗi Composite Key trong Laravel!

---

### A.4. Checkout, Lịch sử Đơn hàng & Thanh toán QR (`Customer\OrderController` & `PaymentController`)

#### 1. Tạo đơn hàng - Checkout (`ORD-01`, `ORD-02`, `ORD-03`)
- **Method & URL:** `POST /api/v1/customer/orders/checkout`
- **Request Body:**
```json
{
  "fulfillmentType": "DELIVERY",
  "recipientName": "Lê Hoàng Hùng",
  "recipientPhone": "0923456789",
  "shippingAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
  "scheduledAt": null,
  "paymentMethod": "BANK_TRANSFER_QR",
  "orderNote": "Gọi điện khi tới sảnh"
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Order created successfully",
  "data": {
    "orderId": 4,
    "orderDate": "2026-06-10T10:15:00",
    "status": "PENDING_CONFIRMATION",
    "fulfillmentType": "DELIVERY",
    "fulfillmentStatus": "PENDING",
    "itemsSubtotal": 650000.00,
    "deliveryFee": 0.00,
    "totalAmount": 650000.00,
    "recipientName": "Lê Hoàng Hùng",
    "recipientPhone": "0923456789",
    "shippingAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
    "scheduledAt": null,
    "orderNote": "Gọi điện khi tới sảnh",
    "payment": {
      "paymentId": 4,
      "amount": 650000.00,
      "paymentMethod": "BANK_TRANSFER_QR",
      "status": "UNPAID"
    },
    "items": [
      {
        "dishId": 1,
        "dishName": "Súp bào ngư",
        "quantity": 2,
        "unitPrice": 150000.00,
        "subTotal": 300000.00
      },
      {
        "dishId": 2,
        "dishName": "Bò sốt tiêu đen",
        "quantity": 1,
        "unitPrice": 350000.00,
        "subTotal": 350000.00
      }
    ]
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Cannot checkout an empty cart",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Checkout online chỉ nhận `DELIVERY` hoặc `PICKUP`; `DINE_IN` do POS tạo khi khách đến bàn. Với `PICKUP`, không gửi `shippingAddress` và có thể gửi `scheduledAt` trong tương lai. Người nhận, số điện thoại, địa chỉ, lịch nhận, ghi chú và loại phục vụ được lưu ở các cột riêng, không ghép tiền tố vào địa chỉ. Toàn bộ checkout chạy trong `DB::transaction()`, snapshot giá món, tạo `Payment` và làm rỗng giỏ hàng.

#### 2. Xem lịch sử, chi tiết đơn hàng & Yêu cầu hủy đơn (`ORD-04`, `ORD-05`, `ORD-06`)
- **Method & URL:**
  - Danh sách lịch sử đơn (`ORD-04`): `GET /api/v1/customer/orders`
  - Xem chi tiết đơn (`ORD-05`): `GET /api/v1/customer/orders/{orderId}`
  - Khách yêu cầu hủy đơn (`ORD-06`): `POST /api/v1/customer/orders/{orderId}/cancel` (Body: `{"cancelReason": "Đổi ý chọn món khác"}`)
- **Response JSON Sample (Success - 200 OK - Khi hủy đơn `ORD-06`):**
```json
{
  "success": true,
  "code": 200,
  "message": "Order cancelled successfully",
  "data": {
    "orderId": 3,
    "status": "CANCELLED"
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Order cannot be cancelled because it is already being prepared by the kitchen",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Khách hàng chỉ hủy được khi `$order->trang_thai === OrderStatus::PENDING_CONFIRMATION`.

#### 3. Tạo mã QR Thanh toán, Xem trạng thái & Thử lại (`PAY-01`, `PAY-02`, `PAY-03`)
- **Method & URL:**
  - Sinh mã QR (`PAY-01`): `POST /api/v1/customer/payments/{orderId}/qr`
  - Kiểm tra kết quả giao dịch (`PAY-02`): `GET /api/v1/customer/payments/{orderId}/status`
  - Thử lại thanh toán khi lỗi/hết hạn (`PAY-03`): `POST /api/v1/customer/payments/{orderId}/retry`
  - Webhook nhận kết quả từ Cổng thanh toán: `POST /api/v1/webhooks/payment-callback`
- **Response JSON Sample (Success - 200 OK - Sinh mã QR):**
```json
{
  "success": true,
  "code": 200,
  "message": "QR code generated successfully",
  "data": {
    "paymentId": 4,
    "orderId": 4,
    "amount": 650000.00,
    "paymentMethod": "BANK_TRANSFER_QR",
    "status": "UNPAID",
    "qrImageUrl": "https://img.vietqr.io/image/970436-1029384756-compact2.png?amount=650000&addInfo=DH4%20THANH%20TOAN",
    "transferContent": "DH4 THANH TOAN",
    "expiresAt": "2026-06-10T10:30:00Z"
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Order has already been paid",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Tích hợp chuẩn VietQR/PayOS/SePay. Khi Webhook gọi về `/api/v1/webhooks/payment-callback`, đối chiếu chữ ký HMAC và số tiền `so_tien` trước khi cập nhật `THANH_TOAN.trang_thai = PaymentStatus::PAID`.

---

### A.5. Đặt bàn Trực tuyến (`Customer\ReservationController`)

#### 1. Tra cứu bàn trống, Đặt bàn, Chỉnh sửa & Hủy đặt bàn (`BKG-01` $\rightarrow$ `BKG-04`)
- **Method & URL:**
  - Tìm bàn trống theo ngày/giờ/số người (`BKG-01`): `GET /api/v1/customer/tables/available?date=2026-06-15&time=19:00&guestCount=4`
  - Tạo lượt đặt bàn (`BKG-02`): `POST /api/v1/customer/reservations`
  - Xem lịch sử đặt bàn của khách: `GET /api/v1/customer/reservations`
  - Sửa lượt đặt bàn (`BKG-03`): `PUT /api/v1/customer/reservations/{reservationId}`
  - Hủy lượt đặt bàn (`BKG-04`): `POST /api/v1/customer/reservations/{reservationId}/cancel`
- **Request Body (`POST /api/v1/customer/reservations`):**
```json
{
  "tableId": 1,
  "reservationTime": "2026-06-15 19:00:00",
  "guestCount": 4,
  "note": "Cần chuẩn bị ghế trẻ em"
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Reservation created successfully",
  "data": {
    "reservationId": 4,
    "reservationTime": "2026-06-15T19:00:00",
    "guestCount": 4,
    "status": "PENDING",
    "note": "Cần chuẩn bị ghế trẻ em",
    "table": {
      "tableId": 1,
      "tableName": "Bàn số 1",
      "capacity": 4,
      "location": "Khu vực sảnh chính"
    }
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Cannot modify or cancel reservation less than 60 minutes before scheduled time",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Dùng thư viện `Carbon` để kiểm tra điều kiện báo trước 2 tiếng (nếu có yêu cầu đặc biệt) và sửa/hủy trước tối thiểu 1 tiếng (`now()->diffInMinutes($reservation->thoi_gian, false) >= 60`).

---

## PHẦN B: ADMIN APIS (PHÂN HỆ QUẢN TRỊ VIÊN)

### B.1. Quản lý Người dùng (`Admin\AccountController`)

#### 1. Xem danh sách, Tìm kiếm, Khóa & Mở khóa tài khoản (`USR-07` $\rightarrow$ `USR-10`)
- **Method & URL:**
  - Danh sách & tìm kiếm (`USR-07`, `USR-08`): `GET /api/v1/admin/accounts?keyword=Hung&status=ACTIVE&page=1`
  - Khóa tài khoản (`USR-09`): `PATCH /api/v1/admin/accounts/{accountId}/lock`
  - Mở khóa tài khoản (`USR-10`): `PATCH /api/v1/admin/accounts/{accountId}/unlock`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Account locked successfully",
  "data": {
    "accountId": 3,
    "username": "kh1",
    "email": "khachhang1@gmail.com",
    "status": "LOCKED",
    "roleName": "Khách hàng",
    "fullName": "Lê Hoàng Hùng",
    "phoneNumber": "0923456789"
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Admin cannot lock their own account",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Trong Model `Account`, thuộc tính `$hidden = ['mat_khau']` đảm bảo mật khẩu không bao giờ xuất hiện trong JSON. Khi khóa tài khoản, gọi `$targetAccount->tokens()->delete()` để đăng xuất cưỡng bức người dùng đó ngay lập tức.

---

### B.2. Quản lý Danh mục & Món ăn (`Admin\CategoryController` & `Admin\DishController`)

#### 1. CRUD Danh mục & Món ăn, Ẩn/Hiện, Còn bán & Xóa mềm (`MNU-09` $\rightarrow$ `MNU-17`)
- **Method & URL:**
  - Thêm / Sửa / Ẩn-Hiện / Xóa mềm Danh mục (`MNU-09` $\rightarrow$ `MNU-12`):
    - `POST /api/v1/admin/categories`
    - `PUT /api/v1/admin/categories/{categoryId}`
    - `PATCH /api/v1/admin/categories/{categoryId}/visibility`
    - `DELETE /api/v1/admin/categories/{categoryId}`
  - Thêm / Sửa / Bật-Tắt Còn bán / Ẩn-Hiện / Xóa mềm Món ăn (`MNU-13` $\rightarrow$ `MNU-17`):
    - `POST /api/v1/admin/dishes`
    - `PUT /api/v1/admin/dishes/{dishId}`
    - `PATCH /api/v1/admin/dishes/{dishId}/availability`
    - `PATCH /api/v1/admin/dishes/{dishId}/visibility`
    - `DELETE /api/v1/admin/dishes/{dishId}`
- **Request Body (`POST /api/v1/admin/dishes`):**
```json
{
  "dishName": "Gà nướng Tây Bắc",
  "price": 280000.00,
  "description": "Gà đi bộ nướng mật ong và mắc khén",
  "imageUrl": "ga_nuong.jpg",
  "categoryId": 2,
  "ingredients": ["Gà ta", "Mật ong", "Mắc khén"],
  "allergens": [],
  "weatherTags": ["RAINY_OR_COLD", "PLEASANT_NORMAL"],
  "isAvailable": true,
  "isVisible": true
}
```
- **Response JSON Sample (Success - 201 Created / 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dish saved successfully",
  "data": {
    "dishId": 3,
    "dishName": "Gà nướng Tây Bắc",
    "price": 280000.00,
    "categoryId": 2,
    "isAvailable": true,
    "isVisible": true,
    "isDeleted": false
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity):**
```json
{
  "success": false,
  "code": 422,
  "message": "Validation failed",
  "errors": {
    "categoryId": ["The selected category id does not exist in DANH_MUC."]
  }
}
```
- **Ghi chú nghiệp vụ (Laravel):** Khi gọi `DELETE /api/v1/admin/dishes/{dishId}`, tuyệt đối **không gọi `$dish->delete()` vật lý** vì sẽ vi phạm khóa ngoại `FK_CTDONHANG_MON_AN` trong bảng `CHI_TIET_DON_HANG`. Thay vào đó, cập nhật cờ xóa mềm (`isDeleted = true`, `isVisible = false`).

---

### B.3. Quản lý Đơn hàng & Thanh toán (`Admin\OrderController` & `Admin\PaymentController`)

#### 1. Xem danh sách đơn, Chuyển trạng thái tuần tự & Hủy đơn (`ORD-07` $\rightarrow$ `ORD-12`)
- **Method & URL:**
  - Danh sách đơn hàng (`ORD-07`): `GET /api/v1/admin/orders?status=PENDING_CONFIRMATION&page=1`
  - Chuyển trạng thái đơn (`ORD-08` $\rightarrow$ `ORD-11`): `PATCH /api/v1/admin/orders/{orderId}/status`
  - Cập nhật trạng thái bàn giao: `PATCH /api/v1/admin/orders/{orderId}/fulfillment-status`
  - Hủy đơn kèm lý do (`ORD-12`): `POST /api/v1/admin/orders/{orderId}/cancel`
- **Request Body (`PATCH /api/v1/admin/orders/{orderId}/status`):**
```json
{
  "targetStatus": "PREPARING"
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Order status updated successfully",
  "data": {
    "orderId": 2,
    "previousStatus": "CONFIRMED",
    "currentStatus": "PREPARING"
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Invalid status transition from COMPLETED to PREPARING",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** `OrderStatus::canTransitionTo()` quản lý tiến độ bếp đến `READY`; không cho chuyển trực tiếp `READY → COMPLETED`. Khi `READY`, đơn giao hàng chuyển sang `AWAITING_DRIVER`, đơn tự lấy chuyển sang `AWAITING_CUSTOMER`. Endpoint `fulfillment-status` tiếp tục luồng `OUT_FOR_DELIVERY → DELIVERED` hoặc `COLLECTED`; trạng thái cuối tự hoàn tất đơn. `DINE_IN` do POS quản lý. Đơn QR phải `PAID` trước khi giao cho tài xế hoặc hoàn tất bàn giao.

#### 2. Tra cứu giao dịch, Đối soát, Hoàn tiền & Xem log lỗi Callback (`PAY-04` $\rightarrow$ `PAY-07`)
- **Method & URL:**
  - Tra cứu giao dịch (`PAY-04`): `GET /api/v1/admin/payments`
  - Đối soát số tiền (`PAY-05`): `POST /api/v1/admin/payments/reconcile`
  - Ghi nhận hoàn tiền (`PAY-06`): `POST /api/v1/admin/payments/{paymentId}/refund`
  - Xem log lỗi Callback (`PAY-07`): `GET /api/v1/admin/payments/callback-logs`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Refund recorded successfully",
  "data": {
    "paymentId": 2,
    "orderId": 2,
    "amount": 135000.00,
    "status": "REFUNDED",
    "refundReason": "Nhà hàng hết nguyên liệu đột xuất"
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Only PAID transactions can be refunded",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Đối chiếu `THANH_TOAN.so_tien === DON_HANG.tong_tien` và lưu vết khi hoàn tiền.

---

### B.4. Quản lý Bàn ăn, Đặt bàn & Báo cáo Thống kê (`Admin\TableController`, `ReservationController`, `ReportController`)

#### 1. Cấu hình Bàn ăn & Điều phối Lượt đặt bàn (`BKG-05` $\rightarrow$ `BKG-12`)
- **Method & URL:**
  - Quản lý bàn & sức chứa (`BKG-05`, `BKG-06`): `GET/POST /api/v1/admin/tables`, `PUT /api/v1/admin/tables/{tableId}`
  - Cấu hình khung giờ (`BKG-07`): `PUT /api/v1/admin/reservations/time-slots`
  - Danh sách đặt bàn (`BKG-08`): `GET /api/v1/admin/reservations`
  - Xác nhận / Từ chối / Hủy / Hoàn thành lượt đặt (`BKG-09` $\rightarrow$ `BKG-12`): `PATCH /api/v1/admin/reservations/{reservationId}/status`
- **Request Body (`PATCH /api/v1/admin/reservations/{reservationId}/status`):**
```json
{
  "targetStatus": "CONFIRMED",
  "adminNote": "Đã giữ Bàn số 2 cạnh cửa sổ"
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Reservation confirmed successfully",
  "data": {
    "reservationId": 3,
    "status": "CONFIRMED",
    "tableId": 2,
    "tableStatus": "RESERVED"
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Table #2 has an overlapping CONFIRMED reservation at this time",
  "errors": null
}
```
- **Ghi chú nghiệp vụ (Laravel):** Đồng bộ tự động trạng thái `BAN_AN.trang_thai` khi Admin duyệt (`CONFIRMED` $\rightarrow$ `RESERVED`) hoặc hoàn tất/hủy (`COMPLETED`/`CANCELLED` $\rightarrow$ `AVAILABLE`).

#### 2. Báo cáo Doanh thu, Đơn hàng, Món bán chạy & Tình hình Đặt bàn (`RPT-01` $\rightarrow$ `RPT-04`)
- **Method & URL:** `GET /api/v1/admin/reports/dashboard?fromDate=2026-06-01&toDate=2026-06-30`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dashboard report generated successfully",
  "data": {
    "revenue": {
      "totalPaidRevenue": 785000.00,
      "dailySeries": [
        { "date": "2026-06-01", "amount": 650000.00 },
        { "date": "2026-06-02", "amount": 135000.00 }
      ]
    },
    "orders": {
      "totalCount": 3,
      "completedCount": 1,
      "cancelledCount": 0,
      "completionRate": 33.33
    },
    "topSellingDishes": [
      { "dishId": 4, "dishName": "Trà đào cam sả", "totalSold": 3, "revenue": 135000.00 },
      { "dishId": 1, "dishName": "Súp bào ngư", "totalSold": 2, "revenue": 300000.00 }
    ],
    "reservations": {
      "totalBookings": 3,
      "confirmedBookings": 2,
      "occupancyRatePercent": 50.00
    }
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity):**
```json
{
  "success": false,
  "code": 422,
  "message": "Invalid date range",
  "errors": {
    "toDate": ["The to date must be a date after or equal to from date."]
  }
}
```
- **Ghi chú nghiệp vụ (Laravel):** Sử dụng Query Builder tổng hợp `DB::table('CHI_TIET_DON_HANG')->join('DON_HANG', ...)->selectRaw('SUM(so_luong) as totalSold, SUM(so_luong * don_gia) as revenue')->groupBy(...)` để đạt hiệu năng cao nhất trên SQL Server.

---

## PHẦN C: STAFF POS, BẾP (KDS) & BỔ SUNG VẬN HÀNH (ADR-019)

> Middleware: `auth:sanctum`, `account.active`, `role:STAFF,ADMIN`. Chi tiết request/response: `restaurant-fe/docs/api/staff-pos.md`.

| Chức năng | Method & URL |
|-----------|-------------|
| Sơ đồ bàn (phiên đang mở + `upcomingReservation`) | `GET /api/v1/staff/pos/tables` |
| Mở phiên (`tableIds`, `guestCount`, `reservationId?`, header `Idempotency-Key`) | `POST /api/v1/staff/pos/sessions` |
| Chi tiết phiên (kèm `reservation`) | `GET /api/v1/staff/pos/sessions/{sessionId}` |
| Hủy phiên mở nhầm (`reason`) | `POST /api/v1/staff/pos/sessions/{session}/cancel` |
| Tạo order / thêm, sửa, xóa món nháp / gửi bếp | `POST /sessions/{session}/orders`, `POST /orders/{order}/items`, `PATCH|DELETE /order-items/{item}`, `POST /orders/{order}/send-kitchen` |
| Lập hóa đơn / mở lại để gọi thêm / đóng phiên | `POST /sessions/{session}/invoices`, `POST /sessions/{session}/reopen`, `POST /sessions/{session}/close` |
| Thanh toán hóa đơn (`method`, `amount`, `transactionCode?`) | `POST /api/v1/staff/invoices/{invoice}/payments` |
| Xác nhận vệ sinh xong | `POST /api/v1/staff/pos/tables/{table}/complete-cleaning` |
| Bảng bếp / chuyển trạng thái món | `GET /api/v1/staff/kitchen/items`, `PATCH /api/v1/staff/kitchen/items/{item}/status` |

### Bổ sung Admin
- `POST /api/v1/admin/payments/{paymentId}/confirm` — xác nhận chuyển khoản thủ công (`transactionCode` bắt buộc 4–100 ký tự, `note` tùy chọn).
- `GET /api/v1/admin/payments` nhận `method` theo tên enum (`CASH`, `BANK_TRANSFER_QR`) và `keyword`.
- `GET /api/v1/admin/reservations` nhận `scope` (`upcoming` | `past` | `all`), trả `isSeated`.
- `AdminOrderResource.availableActions` mô tả các thao tác hợp lệ; đơn `DINE_IN` chỉ do POS/KDS điều phối (409).
- `PUT /api/v1/admin/tables/{tableId}`: `status` thủ công chỉ `AVAILABLE` | `CLEANING` | `MAINTENANCE`.

### Webhook thanh toán
- Header `X-Signature` = HMAC-SHA256 của raw body với `PAYMENT_WEBHOOK_SECRET`. 401 nếu sai chữ ký, 503 nếu chưa cấu hình (trừ `PAYMENT_WEBHOOK_ALLOW_UNSIGNED=true`), 422 nếu thiếu số tiền.

---

## PHẦN D: MỘC LIVE DINING — GỌI MÓN QR TẠI BÀN (ADR-020, ADR-021)

> Luồng: **QR → Check-in → POS session → Order → KDS → Tracking → Service request → Invoice → Payment**.
> Endpoint công khai không cần tài khoản. Bàn được nhận diện bằng `tableToken` (40 ký tự ngẫu nhiên, `BAN_AN.ma_qr`); mỗi thiết bị khách có một **visit token** 64 ký tự gửi trong header `X-Dine-Visit` (DB chỉ lưu SHA-256). Không có endpoint công khai nào nhận `tableId`/`sessionId` từ client.

### D.1. Endpoint công khai (`/api/v1/public/dine/{tableToken}`)

| Method & URL | Rate limit | Mô tả |
| :--- | :--- | :--- |
| `GET /public/dine/{tableToken}?since={version}` | `dine-read` 90/phút | Trạng thái live của bàn. Kèm `X-Dine-Visit` để xem phiên của mình. `since` trùng `version` → chỉ trả `{ changed: false, version, serverTime, sla }`. |
| `POST /public/dine/{tableToken}/check-in` | `dine-entry` 8/phút | `{ guestName (1–40), guestCount (1–30) }` → `201 { visitToken, live }`. 409 nếu bàn đang phục vụ, đã có yêu cầu chờ, bàn bảo trì. |
| `POST /public/dine/{tableToken}/check-in/cancel` | `dine-entry` | Khách tự hủy yêu cầu đang chờ (`PENDING`). |
| `POST /public/dine/{tableToken}/join` | `dine-entry` | `{ joinCode (4 số), guestName? }`. Thiết bị đã thuộc phiên → `200` (không cần mã). Thiết bị mới/pass phiên cũ/yêu cầu `PENDING` → bắt buộc **mã bàn của phiên hiện tại** (ADR-022): đúng → `201` visit mới (hoặc `200` nâng `PENDING`); thiếu mã → 403 (không tính lần sai); sai → 403; sai đủ 5 lần → 423 đến khi nhân viên tạo mã mới. 409 nếu bàn chưa mở. |
| `POST /public/dine/{tableToken}/orders` | `dine-order` 10/phút | Header bắt buộc `Idempotency-Key` (12–64, `[A-Za-z0-9_-]`). Body `{ items: [{ dishId, quantity (1–20), note (≤120) }] }` (≤20 dòng). **Không nhận giá**. `201 { round: { roundNumber, sentAt, itemCount, source: "TABLE_QR" }, created: true, live }`; gửi lại cùng key → `200 created:false`; cùng key khác nội dung → 409. |
| `POST /public/dine/{tableToken}/service-requests` | `dine-service` 12/phút | `{ type: CALL_STAFF|WATER|CUTLERY|CLEAN_TABLE|PAYMENT|OTHER, note (bắt buộc với OTHER, ≤200), preferredMethod: CASH|BANK_TRANSFER_QR }`. Trùng loại đang mở → `200 created:false` (trả yêu cầu cũ). Vừa đóng < 30 giây → 429. |
| `POST /public/dine/{tableToken}/service-requests/{requestId}/cancel` | `dine-service` | Chỉ yêu cầu của chính thiết bị và còn `NEW`. |

**Mã lỗi chung:** 404 `This table QR code is invalid or has been replaced.` · 401 `Your table pass is missing or no longer valid. Scan the table QR code again.` · 409 `This table session has ended. ...` · 409 `Your bill is being prepared. ...` (phiên `AWAITING_PAYMENT`) · 409 `Dish '{name}' has just sold out` · 429 `Too Many Attempts.`

**Response `GET` (rút gọn):**
```json
{
  "success": true,
  "data": {
    "changed": true,
    "version": "4f1c9a0b2d7e8c3a5b6f",
    "serverTime": "2026-10-21T19:42:05+07:00",
    "sla": { "warningMinutes": 10, "lateMinutes": 20 },
    "table": { "tableName": "Bàn L01", "location": "Tầng 1 - ...", "capacity": 4, "status": "OCCUPIED", "inService": true },
    "entry": { "hasActiveSession": true, "requiresJoinCode": false, "joinCodeLocked": false, "hasPendingCheckIn": false },
    "visit": { "status": "ACTIVE", "guestName": "Chị Mai", "guestCount": 3, "rejectReason": null, "requestedAt": "...", "expiresAt": null, "isCurrentSession": true },
    "session": { "status": "OPEN", "openedAt": "...", "closedAt": null, "guestCount": 3, "isCurrent": true, "canOrder": true, "canRequestService": true, "joinCode": "4821" },
    "rounds": [
      { "roundNumber": 1, "source": "TABLE_QR", "isMine": true, "sentAt": "...",
        "items": [{ "lineId": 41, "dishName": "Phở bò đặc biệt", "imageUrl": "/images/menu/pho-bo.jpg", "quantity": 1, "unitPrice": 79000, "note": null, "status": "PREPARING", "statusChangedAt": "..." }] }
    ],
    "serviceRequests": [{ "requestId": 7, "type": "CALL_STAFF", "label": "Gọi nhân viên", "status": "ACKNOWLEDGED", "isMine": true, "canCancel": false, "createdAt": "...", "acknowledgedAt": "...", "completedAt": null }],
    "invoice": { "invoiceNumber": "HD00012", "status": "OPEN", "lines": [{ "dishName": "Phở bò đặc biệt", "quantity": 1, "unitPrice": 79000, "subTotal": 79000 }], "subTotal": 79000, "discountTotal": 0, "totalAmount": 79000, "payment": null, "qr": { "qrImageUrl": "https://img.vietqr.io/...", "transferContent": "HD12 THANH TOAN", "accountName": "MOC DINING", "accountNo": "1029384756" } },
    "summary": { "itemCount": 2, "inKitchenCount": 1, "readyCount": 0, "servedCount": 1, "subTotal": 148000 }
  }
}
```
Không trả `tableId`, `sessionId`, thông tin nhân viên hay dữ liệu khách khác. `session.joinCode` chỉ có khi thiết bị đã thuộc phiên hiện tại; thiết bị bên ngoài chỉ thấy `entry.requiresJoinCode`. Phiên đã đóng vẫn đọc được hóa đơn trong `DINE_VISIT_READABLE_HOURS` (mặc định 6 giờ).

### D.2. Endpoint nhân viên (`auth:sanctum`, `account.active`, `role:STAFF,ADMIN`)

| Method & URL | Mô tả |
| :--- | :--- |
| `GET /staff/live/queue` | `{ counts: { checkIns, newRequests, acknowledgedRequests, paymentRequests }, checkIns: [...], serviceRequests: [...] }`; yêu cầu thanh toán đứng đầu. |
| `POST /staff/live/check-ins/{visitId}/accept` | `{ guestCount?, reservationId? }` → mở phiên qua `PosSessionService::open` (giữ nguyên kiểm tra giữ bàn đặt trước, sức chứa, một phiên mở mỗi bàn; idempotency `dine-check-in-{visitId}`). Nếu bàn đã có phiên thì gắn visit vào phiên đó. Trả `PosSessionResource`. |
| `POST /staff/live/check-ins/{visitId}/reject` | `{ reason (3–255) }` — khách thấy lý do. |
| `POST /staff/live/service-requests/{id}/acknowledge` | `NEW → ACKNOWLEDGED` (khách thấy "Nhân viên đang đến"). |
| `POST /staff/live/service-requests/{id}/complete` | `NEW/ACKNOWLEDGED → COMPLETED`. |
| `GET /staff/live/tables/{tableId}/qr` | `{ tableName, token, url, issuedAt }` để in/hiển thị mã QR. |
| `POST /staff/live/sessions/{sessionId}/join-code` | Tạo mã bàn mới cho phiên đang mở, xóa khóa do nhập sai. Trả `PosSessionResource`. Mã cũng có trong `GET /staff/pos/tables` (`activeSession.joinCode`, `joinCodeLocked`) và `GET /staff/pos/sessions/{id}` (`liveDining.joinCode`, `joinCodeLocked`). |

**Bổ sung vào API hiện có:**
- `GET /staff/pos/tables`: mỗi bàn có `pendingCheckIn` và `activeSession.live` = `{ subTotal, itemCount, draftCount, pendingCount, readyCount, lateCount, oldestPendingSentAt, qrRounds, openRequests, newRequests, paymentRequested, connectedGuests }`, `activeSession.guestName`.
- `GET /staff/pos/sessions/{id}`: thêm `serviceRequests` (30 gần nhất), `liveDining: { connectedGuests, qrUrl }`; mỗi món có `source` (`POS`/`TABLE_QR`), `sentAt`, `statusChangedAt`.
- `GET /staff/kitchen/items`: thêm `sla { warningMinutes, lateMinutes }`; mỗi món có `source` (`POS`/`TABLE_QR`/`ONLINE`), `ticketId`, `sentAt`, `statusChangedAt`.
- Lập hóa đơn tự chuyển yêu cầu `PAYMENT` đang `NEW` → `ACKNOWLEDGED`; thanh toán xong → `COMPLETED`; đóng/hủy phiên → yêu cầu còn mở `CANCELLED`.

### D.3. Endpoint quản trị & webhook

| Method & URL | Mô tả |
| :--- | :--- |
| `POST /admin/tables/{tableId}/qr-token` | Tạo lại mã QR; mã cũ trả 404 ngay, yêu cầu nhận bàn đang chờ của bàn → `EXPIRED`. `GET /admin/tables` trả `qr: { token, url, issuedAt }`. |
| `POST /webhooks/payment-callback` | Ngoài `DH{orderId}`, nhận `HD{invoiceId}` (hoặc trường `invoiceId`) để thanh toán hóa đơn tại bàn qua `BillingService::pay` (HMAC bắt buộc, không đủ tiền → 400, idempotent theo mã giao dịch). |

### D.4. Lệnh demo
`php artisan dining:demo [--rotate] [--json]` — chỉ chạy khi không phải production và `LIVE_DINING_DEMO_ENABLED=true`. Tạo (nếu thiếu) bàn `config('dining.demo.table_name')` (mặc định `Bàn L01`), đóng phiên đã thanh toán hoặc hủy phiên chưa thanh toán của **riêng bàn đó**, hết hạn yêu cầu nhận bàn, đưa bàn về `AVAILABLE` và in link QR. Nếu một phiên đang ghép Bàn L01 với bàn khác, lệnh **từ chối và không thay đổi dữ liệu** (exit code 1).


---

## PHẦN E: NGOẠI LỆ NGHIỆP VỤ (ADR-023 → ADR-027)

Phong bì phản hồi giữ nguyên. Lỗi nghiệp vụ kèm dữ liệu (`DomainConflictException`) đặt chi tiết trong `errors`,
ví dụ `409 { "message": "Prices changed …", "errors": { "reason": "PRICE_CHANGED", "expectedTotal": 90000, "currentTotal": 95000, "prices": [{ "dishId": 1, "unitPrice": 47500 }] } }`.
Quyền: `role:STAFF,ADMIN` cho `/staff/*` trừ khi ghi ADMIN; `/admin/*` chỉ ADMIN.

### E.1. Sửa món & bếp

| Method & path | Body | Ghi chú |
|---|---|---|
| `POST /staff/pos/order-items/{item}/void` | `reason` (ItemVoidReason), `note` (3–255), `quantity?`, `waive?` | SENT/QUEUED: STAFF. Đã nấu: ADMIN (STAFF 403). Trả `PosOrderItem` |
| `POST /staff/pos/order-items/{item}/waive` | `note` | ADMIN, miễn tiền món còn tính tiền |
| `POST /staff/pos/order-items/{item}/remake` | `reason` (lỗi nhà hàng), `note` | 201, dòng `lineType=REMAKE`, `billedQuantity=0` |
| `POST /staff/pos/order-items/{item}/exchange` | `dishId`, `quantity`, `reason`, `note`, `waive?` | 201, dòng nháp `lineType=EXCHANGE` |
| `POST /staff/pos/order-items/{item}/split` | `quantity` (< số phần) | 201, dòng tách |
| `POST /staff/kitchen/items/{item}/reject` | `reason` (`OUT_OF_STOCK|RESTAURANT_MISTAKE|DAMAGED`), `note`, `markSoldOut?` | Trả `KitchenItem` `status=REJECTED` |
| `POST /staff/kitchen/items/{item}/undo` | — | ≤ 120 giây, chưa có bước tiếp; 409 nếu hết hạn |
| `PATCH /staff/kitchen/items/{item}/status` | `targetStatus` | Món của đơn hẹn giờ chưa tới giờ xuống bếp (`kitchenReleaseAt`) → 409 kể cả gọi thẳng API |
| `GET /staff/kitchen/items` | — | Thêm `upcoming[]` (đơn hẹn đã xác nhận, chưa tới giờ xuống bếp), `undoWindowSeconds`; mỗi món thêm `previousStatus`, `canUndo`, `undoUntil`, `lineType`, `originalItemId`, `voidReason`, `isAsap` |

`PosOrderItem` thêm: `billedQuantity`, `subTotal` (= đơn giá × số phần tính tiền), `lineType`, `originalItemId`, `voidReason`, `voidReasonLabel`, `voidNote`, `voidedAt`, `waived`, `requiresApprovalToVoid`.

### E.2. Yêu cầu duyệt

| Method & path | Body | Ghi chú |
|---|---|---|
| `GET /staff/approvals?status=PENDING&sessionId=` | — | Danh sách (tối đa 50) |
| `POST /staff/approvals` | `type` (`ITEM_VOID|SEAT_OUTSIDE_WINDOW|SESSION_WRITE_OFF`), `targetId`, `reason`, `payload{reason?, quantity?, waive?, tableIds?, guestCount?, relocationReason?}` | 201; gửi lại khi đang chờ trả yêu cầu cũ |
| `POST /staff/approvals/{id}/cancel` | — | Người gửi rút |
| `POST /staff/approvals/{id}/approve` | `note?` | ADMIN; thực hiện thao tác trong cùng transaction |
| `POST /staff/approvals/{id}/reject` | `note` | ADMIN |

`Approval`: `approvalId`, `type`, `label`, `status`, `entity`, `targetId`, `sessionId`, `tableNames`, `payload`, `reason`, `requestedBy`, `requestedById`, `decidedBy`, `decisionNote`, `createdAt`, `decidedAt`.

### E.3. Phiên phục vụ & đặt bàn tại quầy

| Method & path | Body | Ghi chú |
|---|---|---|
| `POST /staff/pos/sessions` | thêm `overrideReason?` (ADMIN, ngoài khung giờ), `relocationReason?` (bàn khác bàn đặt), `releaseReservationId?` (nhường bàn sau hạn giữ) | STAFF ngoài khung → 403; vượt sức chứa → 422; ngoài 10:00–22:00 → 409 |
| `PATCH /staff/pos/sessions/{session}/guest-count` | `guestCount`, `reason?` | Trong sức chứa |
| `POST /staff/pos/sessions/{session}/tables` | `tableIds[]`, `reason`, `releaseAs?` (`CLEANING|AVAILABLE|MAINTENANCE`) | Chuyển/ghép bàn |
| `GET /staff/pos/sessions/{session}/history` | — | Nhật ký phiên, món, hóa đơn, yêu cầu duyệt, lượt đặt |
| `POST /staff/pos/sessions/{session}/write-off` | `note` | ADMIN; 409 nếu không còn thiếu |
| `POST /staff/pos/sessions/{session}/reopen` | `reason?` | Bản hóa đơn hiện tại VOID |
| `POST /staff/pos/sessions/{session}/finish-without-charge` | `note` (lý do) | STAFF/ADMIN. Chỉ khi phiên OPEN, mọi món đã xuống bếp bị hủy/từ chối (0 phần tính tiền), không món nháp, không hóa đơn còn hiệu lực, chưa nhận tiền; không thì 409 kèm hướng dẫn. Trả `PosSession` `status=CLOSED`, `endType=NO_CHARGE`; bàn → CLEANING |
| `POST /staff/pos/reservations/{reservation}/extend` | `minutes` (5–30), `reason` | Trả `AdminReservation`. 409 nếu chạm lượt đặt kế tiếp hoặc bàn đang có phiên khách khác trong khoảng giữ mới ("The table is in use by session #… Seat this booking at another table instead.") |
| `POST /staff/pos/reservations/{reservation}/no-show` | `note` | Chỉ sau hạn giữ chỗ |

`PosSession` thêm `expectedEndAt`, `endType`, `writeOff{amount, reason}`, `approvals[]`. Sơ đồ bàn: `activeSession` thêm `tables`, `expectedEndAt`, `overdue`, `nextReservation{reservationId, reservationTime, mustLeaveBy, atRisk}`; `upcomingReservation` thêm `seatingOpensAt`, `holdUntil`, `latestExtension`, `autoNoShowAt`, `minutesLate`, `holdExpired`, `canExtend`, `canMarkNoShow`, `extension`.

### E.4. Hóa đơn & thanh toán tại POS

- `POST /staff/invoices/{invoice}/payments`: `method`, `amount` (> 0, ≤ còn thiếu với tiền mặt), `cashTendered?`, `transactionCode` (bắt buộc ≥ 6 ký tự với chuyển khoản). Thiếu → hóa đơn vẫn `OPEN`, `outstanding` > 0. Header `Idempotency-Key`: gửi lại cùng khóa cùng nội dung trả kết quả cũ, không ghi thêm; cùng khóa khác `amount`/`method`/hóa đơn → 409. Frontend giữ khóa + nội dung của khoản đang gửi đến khi có phản hồi nghiệp vụ.
- `Invoice` thêm `version`, `invoiceNumber` (`HD{id}-V{n}`), `amountPaid`, `outstanding`, `writtenOffAmount`, `refundDue`, `versions[]`; `qr` mã hóa số còn thiếu với nội dung `HD{id}V{n} THANH TOAN`.

### E.5. Webhook

`POST /webhooks/payment-callback` (HMAC `X-Signature`) luôn trả 200 khi đã ghi sổ: `data{transactionId, orderId, duplicateCallback, appliedAmount, reconciliation, flag, status: APPLIED|DUPLICATE_CALLBACK|RECORDED_FOR_REVIEW}`.

- Định danh khoản chuyển: `BANK:{ngân hàng}|{tài khoản nhận}|{mã}`. Ngân hàng lấy từ `gateway`/`provider` (chuẩn hóa theo `payments.bank_aliases`; nhãn chung như `VIETQR`, `NAPAS`, `MANUAL` bị bỏ qua), tài khoản từ `accountNumber`/`account`. Tài khoản nhận đã cấu hình luôn quy về ngân hàng đã cấu hình → xác nhận tay, POS và webhook của cùng khoản tiền trả `DUPLICATE_CALLBACK`. Cùng mã ở ngân hàng/tài khoản khác là khoản khác.
- Ngân hàng nêu rõ luôn được dùng, không suy ra từ số tài khoản: `gateway: Vietcombank` + `accountNumber` trùng dãy số tài khoản cấu hình (MB) là khoản khác, `duplicateCallback=false`, giao dịch `NEEDS_REVIEW` kèm ghi chú. Cùng mã ở định danh khác mà một bên thiếu ngân hàng/tài khoản → ghi riêng, `NEEDS_REVIEW`.
- Đích từ `orderId`/`invoiceId` hoặc nội dung `DH{id}`/`HD{id}V{n}` không tồn tại → vẫn 200, `status=RECORDED_FOR_REVIEW`, `flag=UNKNOWN_TARGET` (không còn 500).

### E.6. Quản trị thanh toán

| Method & path | Body | Ghi chú |
|---|---|---|
| `GET /admin/payments?attention=1` | — | Thêm `amountPaid`, `amountRefunded`, `outstanding`, `refundDue`, `paidAt`, `orderTotal`, `payableAmount` (= tổng đơn − bồi hoàn), `compensationAmount`, `cancellationDecision` |
| `POST /admin/payments/{id}/confirm` | `transactionCode` (≥ 6), `amount?`, `receivedAt?`, `note?` | Gộp với callback cùng định danh; mã đã dùng cho đơn khác → 409. Đơn đã đủ tiền: khoản mới vẫn được ghi (cờ `DUPLICATE`, `NEEDS_REVIEW`, không tính doanh thu); phản hồi thêm `lastReceipt{transactionId, amount, applied, flag, reconciliation}` |
| `POST /admin/payments/{id}/refund` | `refundReason` (≥ 5), `amount`, `method` (`BANK_TRANSFER|CASH|OTHER`), `referenceCode`, `refundedAt?`, `refundType?` (`REFUND` mặc định \| `COMPENSATION`); header `Idempotency-Key` (khuyến nghị) | Ghi nhận đã hoàn theo đơn (có thể trải nhiều giao dịch). Phần vượt `refundDue` trên đơn còn hiệu lực phải gửi `refundType=COMPENSATION` (422 nếu không) — giảm khoản phải thu, hóa đơn giữ PAID. Gửi lại cùng khóa hoặc cùng `referenceCode` + số tiền + hình thức → 200 trả các dòng cũ; khác nội dung → 409 |
| `GET /admin/payments/transactions?reconciliation=NEEDS_REVIEW&keyword=&orderId=` | — | `MoneyTransaction` kèm `allocations[]`, `refunds[]` |
| `POST /admin/payments/transactions/{id}/refunds` | như trên | Ưu tiên hoàn phần chưa phân bổ; cùng quy tắc bồi hoàn/gửi lại |
| `POST /admin/payments/transactions/{id}/allocate` | `orderId`, `amount?`, `note` | Áp tiền chưa phân bổ vào đơn |
| `POST /admin/payments/transactions/{id}/resolve` | `note` | Chỉ khi không còn tiền chưa phân bổ |
| `POST /admin/payments/reconcile` | `paymentIds?` | Thêm `transactionsNeedingReview`, `note`; lý do lệch `SUMMARY_OUT_OF_SYNC|REFUND_DUE|AMOUNT_MISMATCH|ORDER_MISSING` |

`MoneyTransaction` thêm `refundDueAmount` (phần có thể hoàn mà không cần bồi hoàn: chưa phân bổ + phần nhà hàng đang nợ khách trên các đơn đã áp). Dòng hoàn (`refunds[]`) thêm `type` (`REFUND|COMPENSATION`), `fromAllocated` (0 = lấy từ tiền chưa phân bổ). Một yêu cầu hoàn có thể sinh nhiều dòng: mỗi đơn bị rút tiền một dòng (`orderId` của đơn đó), phần chưa phân bổ một dòng; tất cả cùng khóa yêu cầu nên gửi lại so khớp theo tổng.

### E.7. Quản trị đơn hàng (giao/tự lấy)

| Method & path | Body | Ghi chú |
|---|---|---|
| `PATCH /admin/orders/{id}/fulfillment-status` | `targetStatus`; `DELIVERY_FAILED`: `failureReason`, `note`; `COLLECTED`: `pickupCode` hoặc `verifiedPhone`, `collectedBy?`; `AWAITING_DRIVER`: `note?` (giao lại) | Tiền mặt ghi sổ khi `DELIVERED/COLLECTED` |
| `POST /admin/orders/{id}/cancel` | `cancelReason`, `reasonType?`, `moneyDecision?` (`REFUND_DUE|RETAINED`) | Không khi đang giao |
| `PATCH /admin/orders/{id}/recipient` | `recipientName?`, `recipientPhone?`, `shippingAddress?`, `reason` | Trước khi giao tài xế |
| `GET /admin/orders/{id}/history` | — | Nhật ký đơn |
| `GET /admin/orders?fulfillmentStatus=` | — | Lọc bước giao nhận |
| `PATCH /admin/orders/{id}/status` | `targetStatus`, `reason?` | Chuyển đơn hẹn giờ sang `PREPARING`/`READY` trước `kitchenReleaseAt` cần `reason` (422 nếu thiếu), nhật ký `ORDER_STARTED_EARLY` |

`AdminOrder` thêm `kitchenReleaseAt`, `awaitingKitchenRelease`, `isAsap`, `hasPickupCode` (không trả mã), `readyAt`, `handedOverAt`, `collectedBy`, `deliveryAttempts`, `deliveryFailure{reason, reasonLabel, note, at}`, `cancellation{reason, moneyDecision, at, by}`, `handoff{waitMinutes, pickupDeadline, pickupOverdue, driverWaitTooLong}`, `money{amountPaid, amountRefunded, outstanding, refundDue}`; `availableActions` thêm `canRecordDeliveryFailure`, `canRedeliver`, `requiresPickupVerification`, `canEditRecipient`, `canMarkPickupNoShow`, `canRetainMoney`, `startRequiresReason`.

### E.8. Quản trị đặt bàn

| Method & path | Body | Ghi chú |
|---|---|---|
| `PATCH /admin/reservations/{id}/status` | `targetStatus`, `reason?` (bắt buộc với `REJECTED/CANCELLED`), `internalNote?` | `COMPLETED` → 409; `NO_SHOW` sau hạn giữ; `adminNote` cũ vẫn nhận như `reason` |
| `GET /admin/reservations/{id}` | — | Chi tiết |
| `PUT /admin/reservations/{id}` | `reservationTime?`, `tableId?`, `guestCount?`, `internalNote?`, `reason` | Sửa có kiểm tra trùng |
| `GET /admin/reservations/{id}/history` | — | Nhật ký |

`AdminReservation` thêm `expectedEndTime`, `storedStatus`, `internalNote`, `actualGuestCount`, `seatedTables`, `hold{holdUntil, seatingOpensAt, autoNoShowAt, holdExpired}`, `extension{until, reason, by}`, `resolution{at, reason, by}`; `status` là trạng thái hiệu lực (PENDING quá giờ hiện `EXPIRED`, quá hạn tự động hiện `NO_SHOW`).

### E.9. Khách hàng

- `POST /customer/orders/checkout`: thêm `expectedTotal?` (lệch → 409 `PRICE_CHANGED`), `scheduledAt` cho cả giao và tự lấy (tương lai, ≤ 7 ngày, 10:00–22:00); không hẹn khi bếp đóng → 409.
- `PATCH /customer/orders/{id}/recipient`: `recipientName?`, `recipientPhone?`, `shippingAddress?` (trước khi giao tài xế).
- `Order` thêm `isAsap`, `pickupCode` (chỉ đơn tự lấy của chính khách), `readyAt`, `handedOverAt`, `deliveryAttempts`, `deliveryFailure{reason, reasonLabel, at}`, `cancellation{reason, at, moneyDecision}`, `money{…}`, `canEditRecipient`; dòng món thêm `billedQuantity`, `kitchenStatus`, `cancelled`, `cancelReason` (nhãn, không ghi chú nội bộ).
- `POST /customer/payments/{id}/qr|retry`: đơn tiền mặt đã xác nhận → 409 "Payment method is locked once the order is confirmed".
- Đặt bàn: giờ đến 10:00–21:30, ≤ 3 lượt tương lai, không trùng giờ của chính mình, bàn đang có khách bị loại theo giờ dự kiến; `Reservation` thêm `isSeated`, `holdUntil`, `statusReason`; không còn trả ghi chú nội bộ.

### E.10. QR tại bàn

- `POST /public/dine/{token}/orders`: thêm `expectedTotal?`; ngoài 10:00–22:00 → 409; lượt đã nhận (cùng `Idempotency-Key`) vẫn trả 200 `created:false`.
- Trạng thái bàn: `entry.movedTo{tableNames, tableToken}` khi phiên của thẻ đã chuyển bàn, `entry.orderingOpen`, `entry.lastOrderAt`; món trong lượt thêm `billedQuantity`, `cancelReason` (nhãn), `lineType`.

### E.11. Báo cáo

`GET /admin/reports/dashboard`: thêm `finance{salesValue, cancelledOrdersCount, cancelledOrdersValue, moneyReceived, moneyReceivedByMethod, undatedLegacyReceived, appliedToSales, appliedByMethod, averageOrderValue, refunded, undatedLegacyRefunded, netReceived, unallocatedBalance, refundDueBalance, outstandingBalance, voidedValue, waivedValue, remakeValue, writeOffValue, compensationValue, formula}` (`salesValue` đã trừ bồi hoàn); `topSellingDishes[]` mỗi dòng `totalSold` (số phần tính tiền), `revenue` (theo `billedAmount`), `preparedQuantity` (phần bếp làm xong/phục vụ), `freeQuantity` (làm lại/miễn) — món không có phần tính tiền không vào danh sách; `attention` thêm `refundsPending`, `transactionsToReview`, `approvalsPending`, `deliveryFailed`, `driverWaitTooLong`, `pickupOverdue`. `revenue.*` giữ tương thích.

### E.12. Lệnh

- `php artisan dining:reservations:sweep` — hết hạn PENDING, tự no-show, làm mới giữ bàn; lên lịch 5 phút/lần, `withoutOverlapping`.
