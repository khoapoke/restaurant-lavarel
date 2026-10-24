# TẦNG L3: ĐẶC TẢ LOGIC NGHIỆP VỤ CHUYÊN SÂU CHO LARAVEL (LARAVEL BUSINESS LOGIC & VALIDATION RULES)

Tài liệu này mô tả chi tiết các thuật toán xử lý từng bước (Step-by-step Workflows), máy trạng thái (State Machines) và cách triển khai bằng **Laravel Services, DB Transactions & Eloquent Pivot** nhằm tránh hoàn toàn lỗi runtime khi thao tác trên cơ sở dữ liệu `QL_NHAHANG_CNJAVA_V2`.

---

## 1. Quy tắc Xác thực, Tự động Nâng cấp Mật khẩu Mẫu & Khóa Tài khoản (`AuthService`)

### 1.1. Xử lý Lỗi Mật khẩu Dạng rõ (`'123'`) trong Dữ liệu Mẫu SQL Server
Trong script SQL khởi tạo, bảng `TAI_KHOAN` đang chèn mật khẩu dạng chuỗi rõ `'123'` (`INSERT INTO TAI_KHOAN ... VALUES ('admin', '123', ...)`). Nếu dùng `Auth::attempt()` mặc định của Laravel, hàm `BcryptHasher::check()` sẽ ném lỗi hoặc trả về `false` khiến bạn không thể đăng nhập bằng tài khoản mẫu.

**Thuật toán xử lý an toàn trong `AuthService::login()`:**
```text
ALGORITHM: authenticateAccount(username, plainPassword)
-------------------------------------------------------
STEP 1: Truy vấn tài khoản theo 'ten_dang_nhap'
    FETCH $account = Account::with(['role', 'userProfile'])
                        ->where('ten_dang_nhap', $username)
                        ->first();
    IF ($account == NULL) THEN
        THROW UnauthorizedException(401, "Invalid username or password")
    END IF

STEP 2: Kiểm tra mật khẩu (Hỗ trợ cả BCrypt chuẩn và Legacy Plaintext từ file SQL mẫu)
    SET $storedPassword = $account->mat_khau;
    SET $isPasswordValid = false;

    IF (str_starts_with($storedPassword, '$2y$') OR str_starts_with($storedPassword, '$2a$')) THEN
        // Kiểm tra chuẩn BCrypt của Laravel
        $isPasswordValid = Hash::check($plainPassword, $storedPassword);
    ELSE
        // Hỗ trợ tài khoản dữ liệu mẫu ('123') và tự động mã hóa nâng cấp lên BCrypt
        IF (hash_equals($storedPassword, $plainPassword)) THEN
            $isPasswordValid = true;
            $account->mat_khau = Hash::make($plainPassword);
            $account->save(); // Tự động chuyển '123' thành chuỗi BCrypt trong DB
        END IF
    END IF

    IF (!$isPasswordValid) THEN
        THROW UnauthorizedException(401, "Invalid username or password")
    END IF

STEP 3: Kiểm tra trạng thái khóa tài khoản (USR-09)
    IF ($account->trang_thai === AccountStatus::LOCKED) THEN
        THROW ForbiddenException(403, "Your account has been locked by Administrator")
    END IF

STEP 4: Cấp phát Sanctum Personal Access Token
    RETURN $account->createToken('restaurant_api_token')->plainTextToken;
```

### 1.2. Quy tắc Khóa Tài khoản & Bảo mật Mật khẩu (`USR-07` $\rightarrow$ `USR-10`)
- **Rule AUTH-LOCK:** Khi Admin gọi API khóa tài khoản (`PATCH /api/v1/admin/accounts/{accountId}/lock`):
  1. Kiểm tra `$targetAccount->ma_tai_khoan !== $currentAdmin->ma_tai_khoan` (Admin không được tự khóa chính mình).
  2. Cập nhật `$targetAccount->update(['trang_thai' => AccountStatus::LOCKED])`.
  3. Gọi `$targetAccount->tokens()->delete()` để xóa toàn bộ Sanctum Token trong bảng `personal_access_tokens`, buộc tài khoản bị khóa văng khỏi hệ thống ngay lập tức.

---

## 2. Quy tắc Thao tác Giỏ hàng Không Bị Lỗi Khóa Chính Phức Hợp (`CartService`)

Bảng `MON_AN_GIO_HANG` có khóa chính phức hợp `PRIMARY KEY (ma_gio_hang, ma_mon_an)` và không có cột `id` tự tăng. Trong Laravel, nếu khởi tạo `CartItem::where(...)->first()` rồi gọi `$item->save()`, Eloquent sẽ sinh câu lệnh `UPDATE MON_AN_GIO_HANG SET ... WHERE id = null` và gây sập API.

**Thuật toán Chuẩn sử dụng Eloquent `BelongsToMany` Pivot (`CartService`):**
```text
ALGORITHM: addOrUpdateCartItem(accountId, dishId, requestedQuantity)
--------------------------------------------------------------------
STEP 1: Kiểm tra món ăn có tồn tại và đang còn bán không (MNU-15, MNU-16, MNU-17)
    FETCH $dish = Dish::where('ma_mon_an', $dishId)->first();
    IF ($dish == NULL OR !$dish->isAvailable OR !$dish->isVisible OR $dish->isDeleted) THEN
        THROW BadRequestException(400, "Dish is currently unavailable or out of stock")
    END IF

STEP 2: Lấy hoặc khởi tạo Giỏ hàng (1-1 với TAI_KHOAN)
    FETCH $cart = Cart::firstOrCreate(
        ['ma_tai_khoan' => $accountId],
        ['ngay_tao' => now()]
    );

STEP 3: Thao tác trên bảng trung gian MON_AN_GIO_HANG qua Pivot Methods
    FETCH $existingDishInCart = $cart->dishes()
                                     ->wherePivot('ma_mon_an', $dishId)
                                     ->first();

    IF ($existingDishInCart != NULL) THEN
        // Nếu gọi từ API Thêm vào giỏ (POST): Cộng dồn số lượng
        SET $newQuantity = $existingDishInCart->pivot->so_luong + $requestedQuantity;
        $cart->dishes()->updateExistingPivot($dishId, [
            'so_luong' => $newQuantity
        ]);
    ELSE
        // Nếu món chưa có trong giỏ: Chèn bản ghi mới vào MON_AN_GIO_HANG
        $cart->dishes()->attach($dishId, [
            'so_luong' => $requestedQuantity
        ]);
    END IF

STEP 4: Tính toán lại Giỏ hàng và trả về
    LOAD $cart->load('dishes');
    SET $estimatedTotal = $cart->dishes->sum(fn($d) => $d->gia_tien * $d->pivot->so_luong);
    RETURN CartResource($cart, $estimatedTotal);
```

---

## 3. Luồng Checkout Đơn hàng & Máy Trạng thái Đơn hàng (`OrderService`)

### 3.1. Thuật toán Checkout Toàn vẹn Giao dịch (`DB::transaction`)

```text
ALGORITHM: checkoutCartToOrder(accountId, checkoutPayload)
----------------------------------------------------------
EXECUTE inside DB::transaction(function () use ($accountId, $checkoutPayload) {

    STEP 1: Khóa bản ghi Giỏ hàng để chống Race Condition (Double-click Checkout)
        FETCH $cart = Cart::with('dishes')
                          ->where('ma_tai_khoan', $accountId)
                          ->lockForUpdate()
                          ->first();

        IF ($cart == NULL OR $cart->dishes->isEmpty()) THEN
            THROW BadRequestException(400, "Your cart is empty")
        END IF

    STEP 2: Kiểm tra lại trạng thái từng món & Tính tổng tiền kèm Snapshot Đơn giá
        SET $totalAmount = 0;
        SET $orderPivotData = [];

        FOREACH ($cart->dishes AS $dish) {
            IF (!$dish->isAvailable OR !$dish->isVisible OR $dish->isDeleted) THEN
                THROW BadRequestException(400, "Dish '{$dish->ten_mon_an}' has just sold out")
            END IF

            SET $qty = $dish->pivot->so_luong;
            SET $unitPrice = $dish->gia_tien; // Chốt cứng giá tại thời điểm đặt
            $totalAmount += ($unitPrice * $qty);

            // Chuẩn bị mảng dữ liệu cho bảng CHI_TIET_DON_HANG
            $orderPivotData[$dish->ma_mon_an] = [
                'so_luong' => $qty,
                'don_gia'  => $unitPrice
            ];
        }

    STEP 3: Chuẩn hóa dữ liệu nhận hàng theo Hình thức nhận (ORD-01, ORD-02)
        SET $fulfillmentType = $checkoutPayload['fulfillmentType']; // DELIVERY hoặc PICKUP
        SET $fulfillmentStatus = FulfillmentStatus::PENDING;
        SET $recipientName = $checkoutPayload['recipientName'];
        SET $recipientPhone = $checkoutPayload['recipientPhone'];
        SET $shippingAddress = $fulfillmentType === 'DELIVERY'
            ? $checkoutPayload['shippingAddress']
            : NULL;
        SET $scheduledAt = $fulfillmentType === 'PICKUP'
            ? ($checkoutPayload['scheduledAt'] ?? NULL)
            : NULL;
        SET $deliveryFee = 0; // Chính sách MVP: chưa thu phí giao hàng

    STEP 4: Tạo bản ghi DON_HANG
        CREATE $order = Order::create([
            'ngay_dat_hang'     => now(),
            'tong_tien'         => $totalAmount,
            'trang_thai'        => OrderStatus::PENDING_CONFIRMATION, // N'Chờ xác nhận'
            'loai_phuc_vu'      => $fulfillmentType,
            'trang_thai_giao_nhan' => $fulfillmentStatus,
            'ten_nguoi_nhan'    => $recipientName,
            'so_dien_thoai_nhan'=> $recipientPhone,
            'dia_chi_giao_hang' => $shippingAddress,
            'thoi_gian_nhan_du_kien' => $scheduledAt,
            'phi_giao_hang'     => $deliveryFee,
            'ghi_chu'           => $checkoutPayload['orderNote'] ?? NULL,
            'ma_tai_khoan'      => $accountId
        ]);

    STEP 5: Chèn toàn bộ chi tiết món vào CHI_TIET_DON_HANG qua hàm attach()
        $order->dishes()->attach($orderPivotData);

    STEP 6: Khởi tạo bản ghi THANH_TOAN (1-1 với DON_HANG)
        CREATE Payment::create([
            'so_tien'     => $totalAmount + $deliveryFee,
            'phuong_thuc' => $checkoutPayload['paymentMethod'], // N'Tiền mặt' hoặc N'Chuyển khoản'
            'trang_thai'  => PaymentStatus::UNPAID,             // N'Chưa thanh toán'
            'ma_don_hang' => $order->ma_don_hang
        ]);

    STEP 7: Làm rỗng bảng MON_AN_GIO_HANG của khách
        $cart->dishes()->detach();

    RETURN $order->load(['dishes', 'payment']);
});
```

### 3.2. Máy Trạng thái Đơn hàng (`DON_HANG.trang_thai`) & Quy tắc Chuyển đổi

```text
[Khách Checkout]
       │
       ▼
┌──────────────────────┐    (Khách hủy ORD-06 hoặc Admin hủy ORD-12)    ┌─────────────┐
│ PENDING_CONFIRMATION │ ─────────────────────────────────────────────► │  CANCELLED  │
│  (N'Chờ xác nhận')   │                                                │ (N'Đã hủy') │
└──────────┬───────────┘                                                └──────▲──────┘
           │ (Admin duyệt đơn - ORD-08)                                        │
           ▼                                                                   │
┌──────────────────────┐                                                       │
│      CONFIRMED       │ ─────────────────── (Admin hủy có lý do - ORD-12) ────┤
│  (N'Đã xác nhận')    │                                                       │
└──────────┬───────────┘                                                       │
           │ (Admin chuyển bếp nấu - ORD-09)                                   │
           ▼                                                                   │
┌──────────────────────┐                                                       │
│      PREPARING       │ ─────────────────── (Admin hủy sự cố - ORD-12) ───────┤
│ (N'Đang chuẩn bị')   │                                                       │
└──────────┬───────────┘                                                       │
           │ (Món đã nấu xong, chờ giao/lấy - ORD-10)                          │
           ▼                                                                   │
┌──────────────────────┐                                                       │
│        READY         │ ─────────────────── (Admin hủy khách không nhận) ─────┘
│   (N'Sẵn sàng')      │
└──────────┬───────────┘
           │ (FulfillmentStatus đạt DELIVERED/COLLECTED)
           ▼
┌──────────────────────┐
│      COMPLETED       │
│    (Hoàn thành)      │
└──────────────────────┘
```

`DON_HANG.trang_thai` chỉ mô tả tiến độ xác nhận và chuẩn bị món. Sau `READY`, việc bàn giao được quản lý riêng bằng `DON_HANG.trang_thai_giao_nhan`:

- `DELIVERY`: `PENDING → AWAITING_DRIVER → OUT_FOR_DELIVERY → DELIVERED`.
- `PICKUP`: `PENDING → AWAITING_CUSTOMER → COLLECTED`.
- `DINE_IN`: chỉ được tạo từ POS, `SERVING → SERVED`; không xuất hiện trong checkout online.
- Khi bếp chuyển đơn sang `READY`, hệ thống tự đặt trạng thái giao nhận tương ứng (`AWAITING_DRIVER` hoặc `AWAITING_CUSTOMER`). `DELIVERED`/`COLLECTED` mới kết thúc đơn và chuyển `OrderStatus` sang `COMPLETED`.
- Đơn QR phải `PAID` trước khi bàn giao cho tài xế hoặc hoàn tất nhận món. Đơn tiền mặt được ghi nhận `PAID` khi khách nhận hàng/món.

- **Quy tắc Hủy đơn của Khách hàng (`ORD-06`):**
  - `IF ($order->trang_thai !== OrderStatus::PENDING_CONFIRMATION)` $\rightarrow$ Trả về lỗi `409 Conflict` ("Nhà hàng đã tiếp nhận hoặc đang chế biến đơn hàng của bạn, không thể tự hủy").
- **Quy tắc Đồng bộ Thanh toán khi Hoàn thành Đơn hàng (`ORD-11`):**
  - Admin không chuyển trực tiếp `READY → COMPLETED`; hệ thống tự hoàn tất sau khi cập nhật giao nhận thành `DELIVERED` hoặc `COLLECTED`.
  - Nếu phương thức thanh toán là `PaymentMethod::CASH` (`N'Tiền mặt'`), hệ thống tự động cập nhật `$order->payment->update(['trang_thai' => PaymentStatus::PAID])` tại thời điểm bàn giao cuối.
  - Nếu phương thức là `BANK_TRANSFER_QR`, giao dịch phải ở trạng thái `PAID` trước khi bàn giao cho tài xế hoặc kết thúc nhận món.
    - Nếu phương thức thanh toán là `PaymentMethod::BANK_TRANSFER_QR` (`N'Chuyển khoản'`) mà `THANH_TOAN.trang_thai` vẫn là `UNPAID`, hệ thống chặn chuyển `COMPLETED` và báo lỗi `400 Bad Request` ("Đơn hàng chuyển khoản QR chưa được xác nhận thanh toán thành công").

---

## 4. Luồng Đặt bàn & Kiểm tra Xung đột Khung giờ bằng Carbon (`ReservationService`)

### 4.1. Quy tắc Chuyển Trạng thái Bàn ăn (`BAN_AN.trang_thai`) & Phiếu Đặt bàn (`DAT_BAN.trang_thai`)
1. **Kiểm tra Sức chứa (`BKG-02`, `BKG-06`):**
   - Số lượng khách đặt (`so_luong_khach`) phải thỏa mãn: $1 \le \text{guestCount} \le \text{BAN\_AN.suc\_chua}$.
2. **Kiểm tra Thời gian Báo trước (`Chính sách 1.2.5.a`):**
   - Thời gian đặt bàn (`thoi_gian`) phải lớn hơn thời điểm hiện tại ít nhất **30 phút**.
   - Nếu trong `ghi_chu` (`note`) có các từ khóa yêu cầu đặc biệt (`["sinh nhật", "phòng riêng", "vip", "chay", "trang trí"]`), thời gian đặt phải cách hiện tại tối thiểu **120 phút (02 giờ)**:
     ```php
     if ($this->hasSpecialRequest($note) && now()->diffInMinutes($reservationTime, false) < 120) {
         throw new BusinessException('Special requests require at least 2 hours advance notice.', 400);
     }
     ```
3. **Kiểm tra Trùng khung giờ đặt bàn ($\pm 120\text{ phút}$):**
   - Mỗi ca dùng bữa tiêu chuẩn kéo dài $120\text{ phút}$. Một bàn `ma_ban` được coi là **còn trống** tại thời điểm $T_{\text{req}}$ khi không tồn tại bản ghi nào trong `DAT_BAN` thỏa mãn đồng thời:
     - `ma_ban == $tableId`
     - `trang_thai IN (N'Chờ xử lý', N'Đã xác nhận')`
     - `thoi_gian BETWEEN $T_req->copy()->subMinutes(119) AND $T_req->copy()->addMinutes(119)`
4. **Khi nào `BAN_AN.trang_thai` chuyển sang `N'Đã đặt'` (`RESERVED`)?**
   - Khi Admin bấm xác nhận phiếu đặt bàn (`PATCH /api/v1/admin/reservations/{id}/status` $\rightarrow$ `CONFIRMED`), bản ghi `DAT_BAN.trang_thai` chuyển thành `N'Đã xác nhận'`. Nếu lịch đặt diễn ra trong ca hiện tại/trong ngày, `BAN_AN.trang_thai` chuyển thành `N'Đã đặt'`.
   - Khi lượt đặt chuyển sang `COMPLETED` (`N'Hoàn thành'`), `CANCELLED` (`N'Đã hủy'`) hoặc `REJECTED` (`N'Từ chối'`), `BAN_AN.trang_thai` được giải phóng về `N'Trống'` (`AVAILABLE`).
5. **Điều kiện Sửa hoặc Hủy Đặt bàn của Khách hàng (`BKG-03`, `BKG-04` - `Chính sách 1.2.5.b`):**
   - Khách hàng chỉ được phép sửa hoặc hủy khi:
     ```php
     $minutesUntilArrival = now()->diffInMinutes($reservation->thoi_gian, false);
     if ($minutesUntilArrival < 60) {
         throw new ConflictHttpException('Reservations can only be modified or cancelled at least 60 minutes prior to arrival time.');
     }
     ```

---

## 5. Thuật toán Gợi ý Món ăn theo Thời tiết & Lọc Cứng Chất Dị ứng (`RecommendationService`)

```text
ALGORITHM: getWeatherAndAllergySafeRecommendations(account, lat, lon)
---------------------------------------------------------------------
STEP 1: Lấy danh sách chất dị ứng của Khách hàng (USR-06)
    SET $userAllergies = [];
    IF ($account != NULL AND $account->userProfile != NULL) THEN
        $userAllergies = $account->userProfile->allergies ?? []; // VD: ["SHELLFISH", "PEANUT"]
    END IF

STEP 2: Truy vấn dữ liệu Thời tiết từ OpenWeatherMap API (Có Cache 15 phút)
    // Chỉ cache dữ liệu thời tiết. Danh sách gợi ý dựng lại mỗi lần với dị ứng hiện tại của hồ sơ và
    // thực đơn hiện tại (món/danh mục ẩn, hết bán) — không cache kết quả đã lọc theo dị ứng.
    SET $cacheKey = "weather_data_" . round($lat, 2) . "_" . round($lon, 2);
    FETCH $weather = Cache::remember($cacheKey, 900, function () use ($lat, $lon) {
        RETURN Http::timeout(3)->get('https://api.openweathermap.org/data/2.5/weather', [
            'lat'   => $lat,
            'lon'   => $lon,
            'appid' => config('services.openweather.key'),
            'units' => 'metric',
            'lang'  => 'vi'
        ])->json();
    });

    SET $tempC = $weather['main']['temp'] ?? 28.0;
    SET $conditionMain = $weather['weather'][0]['main'] ?? 'Clear';

STEP 3: Xác định Nhóm danh mục & Tag món ăn ưu tiên theo Thời tiết
    IF ($tempC >= 31.0 AND !in_array($conditionMain, ['Rain', 'Thunderstorm'])) THEN
        // Trời nắng nóng -> Ưu tiên Đồ uống giải nhiệt, Tráng miệng mát lạnh, Khai vị nhẹ
        SET $weatherType = 'HOT_SUNNY';
        SET $priorityCategories = ['Đồ uống', 'Tráng miệng', 'Khai vị'];
    ELSE IF ($tempC <= 24.0 OR in_array($conditionMain, ['Rain', 'Thunderstorm', 'Drizzle'])) THEN
        // Trời mưa hoặc se lạnh -> Ưu tiên Món chính nóng, Súp, Nướng, Lẩu
        SET $weatherType = 'RAINY_OR_COLD';
        SET $priorityCategories = ['Món chính', 'Khai vị'];
    ELSE
        // Thời tiết mát mẻ dễ chịu
        SET $weatherType = 'PLEASANT_NORMAL';
        SET $priorityCategories = ['Món chính', 'Khai vị', 'Đồ uống', 'Tráng miệng'];
    END IF

STEP 4: Lọc Cứng Dị ứng bằng Laravel Collection (STRICT ALLERGEN EXCLUSION)
    FETCH $dishes = Dish::with('category')->active()->get();

    SET $safeDishes = $dishes->reject(function (Dish $dish) use ($userAllergies) {
        // QUY TẮC BẮT BUỘC: Nếu giao tập hợp giữa allergens của món và userAllergies khác rỗng -> Loại bỏ 100%
        $dishAllergens = $dish->allergens ?? [];
        return count(array_intersect($dishAllergens, $userAllergies)) > 0;
    });

STEP 5: Chấm điểm độ phù hợp Thời tiết & Sắp xếp kết quả
    SET $rankedDishes = $safeDishes->sortByDesc(function (Dish $dish) use ($priorityCategories, $weatherType) {
        $score = 0;
        if (in_array($dish->category?->ten_danh_muc, $priorityCategories)) {
            $score += 10;
        }
        if (in_array($weatherType, $dish->weatherTags ?? [])) {
            $score += 20;
        }
        return $score;
    })->values()->take(6);

    RETURN [
        'weather'           => ['temperatureCelsius' => $tempC, 'condition' => $weatherType],
        'excludedAllergens' => $userAllergies,
        'recommendedDishes' => DishResource::collection($rankedDishes)
    ];
```

---

## 6. Mộc Live Dining — Gọi món QR tại bàn (`Services/Dining/*`)

### 6.1. Token bàn và token thiết bị (`GuestAccessService`)
- `BAN_AN.ma_qr`: `Str::random(40)` (≈238 bit), unique, sinh tự động khi tạo bàn, **không mass-assignable**; admin tạo lại bằng `rotateQrToken()` → mã cũ 404 ngay.
- Visit token: `Str::random(64)` trả cho thiết bị đúng một lần; DB lưu `sha256` (`LUOT_KHACH_BAN.token_hash`, unique). Mọi truy vấn visit đều kèm `ma_ban` của bàn vừa quét → đổi URL sang bàn khác không dùng được token cũ (401/không thấy dữ liệu).
- Visit chỉ thao tác được khi `trang_thai = ACTIVE` **và** `ma_phien` = phiên đang mở của bàn. Phiên kết thúc → visit chỉ còn quyền đọc hóa đơn trong `visit_readable_hours`. Phiên đã chuyển/ghép bàn: quyền (kể cả quyền đọc sau khi đóng) xét theo phiên của visit có chứa bàn đang quét, không chỉ bàn quét ban đầu; không bao giờ mở sang phiên mới của bàn.
- Yêu cầu nhận bàn `PENDING` quá `check_in_expires_minutes` (15) coi như `EXPIRED`. Tối đa 30 thiết bị/phiên.
- **Mã bàn theo phiên (ADR-022):** QR in cố định chỉ nhận diện bàn. Thiết bị chưa thuộc phiên (không pass, pass phiên cũ, hoặc yêu cầu `PENDING`) chỉ vào được khi gửi đúng `PHIEN_PHUC_VU.ma_tham_gia` (4 số, sinh mới cho mỗi phiên) hoặc khi nhân viên chấp nhận check-in. Khóa phiên + `hash_equals`; mỗi mã sai tăng `so_lan_sai_ma`, đủ 5 → 423 tới khi nhân viên tạo mã mới; thiếu mã không tính là lần sai. Mã chỉ hiển thị cho nhân viên và thiết bị đã trong phiên.

### 6.2. Check-in (`CheckInService`)
1. Khóa `BAN_AN` (`lockForUpdate`). Bàn `MAINTENANCE` → 409; bàn đã có phiên mở → 409 (khách dùng `join`); đã có yêu cầu `PENDING` còn hạn → 409. Số khách tối đa 2× sức chứa.
2. Nhân viên chấp nhận: khóa visit → nếu bàn chưa có phiên thì gọi `PosSessionService::open` (idempotency `dine-check-in-{visitId}`) nên **vẫn áp dụng** giữ bàn đặt trước ±119 phút, sức chứa, một phiên mở/bàn; có thể truyền `reservationId` để liên kết lượt đặt. Nếu bàn đã có phiên (mở tay tại POS) thì gắn visit vào phiên đó.
3. Từ chối bắt buộc lý do 3–255 ký tự; khách thấy lý do.

### 6.3. Gửi món (`TableOrderService::submit`)
- `request_hash = sha256(visitId + dòng món đã chuẩn hóa)`. Cùng `Idempotency-Key` + cùng hash → trả lượt cũ (`created:false`); khác hash hoặc khác visit → 409. Bắt `UniqueConstraintViolationException` khi hai request trùng key chạy song song.
- Transaction: khóa phiên → kiểm tra visit gắn đúng phiên, phiên `OPEN` (`AWAITING_PAYMENT` → 409 mời gọi nhân viên) → lấy/tạo **đơn POS duy nhất của phiên** (`kenh=POS`, `DINE_IN`) → khóa `MON_AN`, kiểm tra hiển thị/còn bán/chưa xóa → tạo `CHI_TIET_DON_HANG` trạng thái `SENT` với `ten_mon_snapshot`, `don_gia = MON_AN.gia_tien` → tạo `PHIEU_BEP` (`nguon=TABLE_QR`, `ma_luot`, `ma_nhan_vien` = nhân viên phụ trách phiên) → đơn `PREPARING`, tính lại tổng.
- Ghi chú được `strip_tags` + gộp khoảng trắng. Không có endpoint sửa/xóa món sau khi gửi; khách gọi lượt mới.
- Kiểm tra replay (`findReplay`) chạy **trước** kiểm tra trạng thái phiên, nên gửi lại một lượt đã nhận luôn được xác nhận `created:false` (kể cả khi phiên đã `AWAITING_PAYMENT`), còn lượt mới thì bị chặn. Frontend lưu lượt đang gửi vào `localStorage` để reload/tab khác gửi lại đúng key và payload.
- Lệnh demo từ chối toàn bộ (trước mọi thao tác ghi) nếu một phiên ghép Bàn L01 với bàn khác.

### 6.4. Yêu cầu phục vụ (`ServiceRequestService`)
- Vòng đời `NEW → ACKNOWLEDGED → COMPLETED`, khách hủy khi còn `NEW` (chỉ yêu cầu của chính thiết bị). Nhân viên được hoàn tất thẳng từ `NEW`.
- Chống spam: cùng loại đang mở → trả yêu cầu cũ; cùng loại vừa đóng/hủy < `cooldown_seconds` (30) → 429; `OTHER` tối đa 3 yêu cầu mở, trùng nội dung → trả yêu cầu cũ; rate limit `dine-service`.
- `PAYMENT` là ưu tiên (đứng đầu hàng đợi). Lập hóa đơn → `ACKNOWLEDGED`; thanh toán → `COMPLETED`; đóng/hủy phiên → mọi yêu cầu mở `CANCELLED`.

### 6.5. Theo dõi trực tiếp (`LiveStateService`)
- Snapshot chỉ chứa dữ liệu phiên của visit; `version` = 20 ký tự đầu SHA-256 của snapshot. Client gửi `since=version` để nhận `changed:false` khi không đổi.
- Thời gian chờ tính từ `PHIEU_BEP.gui_luc`; `CHI_TIET_DON_HANG.bep_cap_nhat_luc` ghi lần đổi trạng thái bếp gần nhất. SLA: `KITCHEN_SLA_WARNING_MINUTES` (10), `KITCHEN_SLA_LATE_MINUTES` (20).


## 7. Ngoại lệ nghiệp vụ (ADR-023 → ADR-028, 2026-10-21)

Chính sách đầy đủ và các con số cấu hình: `docs/operations/exception-policies.md`. Ánh xạ 89 case: `docs/reviews/business-edge-case-mapping.md`.

### 7.1. Sổ tiền (`PaymentLedgerService`)
- Mọi khoản tiền thật ghi vào `GIAO_DICH_TIEN` trước khi áp. Chuyển khoản: khóa duy nhất `BANK:{ngân hàng}|{tài khoản nhận}|{MÃ}` (`TransferIdentity`, ADR-028) — ngân hàng nêu rõ (tên/alias/BIN) luôn được dùng, không suy ra từ số tài khoản; nguồn không nêu ngân hàng (nhãn chung `VIETQR`, `MANUAL`, `POS`…) với tài khoản trống/trùng tài khoản cấu hình → tài khoản nhận đã cấu hình; nêu ngân hàng cấu hình mà thiếu số tài khoản → tài khoản cấu hình. Dãy số trùng tài khoản cấu hình ở ngân hàng khác, hoặc cùng mã ở định danh khác mà một bên thiếu ngân hàng/tài khoản → ghi riêng, `NEEDS_REVIEW` kèm ghi chú. Tiền mặt/khác: `NHÀ_CUNG_CẤP|tài_khoản|mã`. Cùng khóa → trả lại giao dịch cũ (callback trùng, xác nhận tay sau webhook không tạo thêm tiền).
- Đích đơn/hóa đơn chỉ gán khi tồn tại; không → `UNKNOWN_TARGET`, `NEEDS_REVIEW`. Ghi tay khoản mới cho đơn đã đủ tiền: ghi nhận, cờ `DUPLICATE`.
- Áp vào đơn: `min(chưa phân bổ, còn thiếu)`; đơn đã hủy → cờ `ORDER_CANCELLED`; đơn đủ tiền → cờ `DUPLICATE`; còn dư → cờ `OVERPAID`, giao dịch `NEEDS_REVIEW`.
- `phải thu = tổng đơn − giảm trừ (so_tien_giam_tru)`. `chưa phân bổ = số tiền − đã phân bổ (ròng) − đã hoàn`. `đã thu của đơn = Σ phân bổ`. `còn thiếu = max(0, phải thu − đã thu)` (đơn hủy: 0). `phải hoàn = đơn hủy ? (RETAINED ? 0 : đã thu) : max(0, đã thu − phải thu)`.
- Trạng thái tổng hợp `THANH_TOAN`: chưa thu → `UNPAID`; đã thu 0 và có hoàn → `REFUNDED`; đơn hủy có hoàn → `PARTIALLY_REFUNDED`; đã thu ≥ phải thu và có bồi hoàn → `PARTIALLY_REFUNDED`; đã thu ≥ phải thu → `PAID`; còn lại `PARTIALLY_PAID`.
- POS: tiền mặt ≤ còn thiếu (phần khách đưa dư là tiền thối, gửi `cashTendered`); chuyển khoản bắt buộc mã ≥ 6 ký tự; mã đã được webhook ghi → gộp, không ghi trùng; mã đã áp cho đơn khác → 409. Cùng `Idempotency-Key` khác số tiền/hình thức/hóa đơn → 409. Đóng phiên khi còn thiếu = 0.
- Hoàn tiền (ADMIN): số tiền > 0, ≤ đã nhận − đã hoàn của giao dịch/đơn; lý do ≥ 5 ký tự, phương thức, mã chứng từ, thời điểm hoàn ≤ hiện tại. Loại `REFUND` ≤ phải hoàn; phần vượt trên đơn còn hiệu lực chỉ được ghi là `COMPENSATION` (422 nếu không chọn) và cộng vào `so_tien_giam_tru`.
- Một yêu cầu hoàn = nhiều dòng `HOAN_TIEN` cùng `khoa_yeu_cau`: mỗi đơn bị rút tiền một dòng (`so_tien_tu_phan_bo = so_tien`, nối dòng đảo `PHAN_BO_THANH_TOAN.ma_hoan_tien`), phần tiền chưa phân bổ một dòng (`so_tien_tu_phan_bo = 0`, đơn đích của giao dịch). `đã hoàn của đơn = Σ HOAN_TIEN.so_tien` theo đơn.
- Gửi lại hoàn tiền: khóa yêu cầu = `KEY:{Idempotency-Key}` hoặc `VOUCHER:O{đơn}|{chứng từ}` / `VOUCHER:T{giao dịch}|{chứng từ}`; cùng khóa cùng số tiền + hình thức → trả các dòng đã ghi; khác → 409.
- Báo cáo: giá trị bán (đã trừ bồi hoàn) theo ngày đặt; tiền nhận theo `nhan_luc`; hoàn theo `hoan_luc`; tiền cũ không rõ ngày tách riêng. Món bán chạy: số phần tính tiền và `billedAmount`; dòng làm lại/miễn/hủy không cộng doanh thu (tách `preparedQuantity`, `freeQuantity`).

### 7.2. Món & bếp (`ItemCorrectionService`, `KitchenService`)
- Hủy: SENT/QUEUED → STAFF, lý do bắt buộc, số phần tính tiền giảm tương ứng. PREPARING/READY/SERVED → chỉ ADMIN (STAFF 403, gửi `ITEM_VOID`). Lý do thuộc lỗi nhà hàng (`ORDER_ENTRY_ERROR`, `OUT_OF_STOCK`, `RESTAURANT_MISTAKE`, `DAMAGED`, `NOT_SERVED`) → không tính tiền; `CUSTOMER_CHANGED_MIND` sau khi nấu → vẫn tính trừ khi ADMIN miễn (`waive`).
- Món thuộc hóa đơn khi số phần tính tiền > 0, kể cả đã hủy; phiên có món như vậy không được hủy.
- Bếp từ chối: SENT/QUEUED/PREPARING, lý do `OUT_OF_STOCK|RESTAURANT_MISTAKE|DAMAGED`, tùy chọn báo hết món (`con_ban=false`).
- Làm lại: chỉ món READY/SERVED/CANCELLED/REJECTED, lý do lỗi nhà hàng; dòng mới REMAKE giá 0, phiếu bếp mới. Đổi món: chỉ tại bàn. Tách: 1 ≤ phần tách < số phần.
- Hoàn tác: chỉ bước vừa bấm, trong 120 giây, khi trạng thái chưa đổi tiếp.
- Đơn online hẹn giờ: `kitchenReleaseAt = giờ hẹn − scheduled_prep_lead_minutes`. Trước mốc: KDS không hiện, API bếp từ chối chuyển trạng thái (409); chỉ quản lý cho làm sớm qua `PATCH /admin/orders/{id}/status` kèm `reason` (ghi `ORDER_STARTED_EARLY`).
- Phiên mà mọi món đã xuống bếp đều bị hủy/từ chối (0 phần tính tiền), không nháp, không hóa đơn còn hiệu lực, chưa nhận tiền → `finish-without-charge` (lý do), `kieu_ket_thuc = NO_CHARGE`.
- Mọi thay đổi trên hóa đơn mở → VOID bản hiện tại, lập bản mới; hóa đơn đã PAID giữ nguyên và hiện "phải hoàn".

### 7.3. Đặt bàn & phiên (`ReservationSchedule`, `ReservationDeskService`, `PosSessionService`)
- Giờ đến ∈ [10:00, 21:30]; báo trước 30 phút (120 phút với yêu cầu đặc biệt, chỉ xét ghi chú của khách); ≤ 3 lượt tương lai/tài khoản, không chồng giờ của chính khách.
- Bàn trống khi không có lượt PENDING (chưa tới giờ)/CONFIRMED (còn giữ chỗ) chồng `[bắt đầu, kết thúc + 15)` và không có phiên chiếm tới giờ dự kiến (quá giờ: + 60 phút).
- Nhận khách: [giờ hẹn − 30, hạn giữ chỗ]; ngoài khung chỉ ADMIN + `overrideReason`; bàn khác cần `relocationReason`; số khách ≤ tổng sức chứa các bàn; `releaseReservationId` chỉ khi lượt đó đã quá hạn giữ chỗ.
- Gia hạn: 5–30 phút, lý do, cộng dồn tới hạn gốc + 30, không chồng lượt kế tiếp, không chồng phiên đang chiếm bàn (khách vãng lai đã được nhường bàn), chưa quá mốc tự động no-show. Không đến: chỉ sau hạn giữ chỗ.
- Admin: hủy/từ chối bắt buộc lý do; `COMPLETED` không bấm tay; sửa lượt đặt kiểm tra giờ, bàn bảo trì, sức chứa, trùng lịch.
- Đổi số khách: 1 ≤ số khách ≤ sức chứa. Chuyển/ghép bàn: bàn mới AVAILABLE/RESERVED, không thuộc phiên khác, không trùng lượt đặt trong thời gian phiên còn chiếm, đủ chỗ; lý do bắt buộc.
- Gọi món mới (POS gửi bếp, QR, mở phiên) chỉ 10:00–22:00.

### 7.4. Giao hàng & tự lấy (`Admin\OrderService`)
- `OUT_FOR_DELIVERY → DELIVERED | DELIVERY_FAILED`; `DELIVERY_FAILED → AWAITING_DRIVER`. Ghi thất bại bắt buộc nguyên nhân + mô tả ≥ 3 ký tự.
- Đơn QR phải đủ tiền trước khi giao tài xế/bàn giao. Đơn tiền mặt ghi `COD` khi `DELIVERED`/`COLLECTED`.
- `COLLECTED` bắt buộc đúng `pickupCode` hoặc `verifiedPhone` (chuẩn hóa +84 → 0); `collectedBy` lưu người lấy hộ.
- Hủy: không khi đang giao; `reasonType` hợp lệ; `PICKUP_NO_SHOW` chỉ khi quá hạn giữ món; `RETAINED` chỉ khi lỗi do khách; `ADDRESS_UNSERVICEABLE` luôn phải hoàn.
- Sửa người nhận: trước khi giao tài xế/bàn giao, đơn chưa kết thúc; admin cần lý do.
- Checkout: giờ hẹn ở tương lai, ≤ 7 ngày, trong giờ nhận đơn; không hẹn thì bếp phải đang nhận đơn; `expectedTotal` lệch → 409 `PRICE_CHANGED`.

## 8. Email, thông báo & chat hỗ trợ (ADR-029, `Services/Shared/*`, 2026-10-23)

### 8.1. Inbox + outbox (`GuestCommunicationService`)
- Observer của `Reservation`/`Order`/`Payment` ghi thông báo trong ứng dụng và dòng email cùng transaction với thay đổi nghiệp vụ
  (rollback cùng nhau). Khóa sự kiện `event_key` duy nhất; SMTP/Gemini không bao giờ chạy trong transaction nghiệp vụ.
- Lượt đặt PENDING ghi rõ "chưa phải xác nhận giữ bàn"; chỉ CONFIRMED nói giữ chỗ tới giờ nào. Không đưa ghi chú nội bộ vào thông báo.
- Trước khi gửi, kiểm tra lại: tài khoản còn hoạt động, email chưa đổi, thông báo chưa bị thay thế (trạng thái/giờ của lượt đặt
  hoặc đơn vẫn như lúc ghi), nhắc lịch chưa hủy/đổi lịch/đã nhận bàn và giờ hẹn chưa qua, chat vẫn còn tin chưa đọc.

### 8.2. Hạn gửi email tự động (`emailExpiresAt`, cấu hình `communications.email_ttl_minutes`)

| Loại | Hạn mặc định (tính từ lúc tạo thông báo) |
|---|---|
| Tiếp nhận / xác nhận / cập nhật đặt bàn hoặc đơn | 2 giờ |
| Hủy / từ chối / hết hạn / không đến (đặt bàn), hủy đơn, giao hàng chưa thành công | 24 giờ |
| Chat chưa đọc | 24 giờ |
| Mời đánh giá | 48 giờ |
| Biên nhận thanh toán | 7 ngày |
| Nhắc đặt bàn | tới đúng giờ hẹn |
| Loại khác | 24 giờ |

- `now ≥ expires_at` → `SKIPPED` + `EMAIL_EXPIRED` (dispatcher đánh dấu trước khi đưa vào hàng đợi; job kiểm lại ngay trước khi gửi).
  Thông báo trong ứng dụng, chứng từ và lịch sử không bị xóa. Retry tự động và gửi lại thủ công không gia hạn; gửi lại thư đã quá
  hạn → 409. Dòng outbox tạo trước khi có cột hạn được bổ sung hạn theo lúc tạo thông báo.

### 8.3. Kết quả SMTP (`DeliverGuestNotice::classify`)
- Phân loại theo mã phản hồi SMTP Symfony Mailer gắn vào exception (`UnexpectedResponseException`/`TransportException::getCode()`),
  không dò số trong chuỗi lỗi.
- 4xx (máy chủ trả lời rõ là chưa nhận, thử lại sau; gồm 4xx khi xác thực) → `RETRY` sau 2^n phút, tối đa 3 lần rồi `FAILED`.
- 5xx → `FAILED` `SMTP_FAILURE_REVIEW_REQUIRED`. Timeout/mất kết nối (mã 0, có thể đã nhận DATA) → `FAILED`
  `SEND_OUTCOME_UNKNOWN_REVIEW_REQUIRED`. Lỗi không phải SMTP → `SEND_ERROR_REVIEW_REQUIRED`. Không tự gửi lại các trường hợp này.
- Không lưu/ghi log message của exception (có thể chứa tên đăng nhập SMTP, địa chỉ, nội dung máy chủ); log chỉ có mã lỗi, mã SMTP, số lần thử, tên lớp exception.

### 8.4. Mời đánh giá sau bữa ăn
- Sau `feedback_delay_minutes` (120) kể từ thông báo hoàn tất, trong vòng 24 giờ; chỉ khi bữa đó thực sự `COMPLETED` của chính tài khoản.
- Định danh bữa: bữa tại bàn theo **phiên POS** (`session:{ma_phien}` — lượt đặt và đơn POS cùng phiên là một bữa; lượt đặt chưa có phiên
  dùng `reservation:{id}`); đơn online theo đơn (`order:{id}`).
- Tối đa một lời mời mỗi bữa (`event_key = feedback:{meal}` duy nhất) và một lời mời/ngày/tài khoản (`limit_key = feedback-day:{account}:{ngày}`
  duy nhất). Vi phạm ràng buộc → không ghi gì; bữa bị giới hạn ngày có thể được mời ở lượt quét sau nếu còn trong khung.

### 8.5. Biên nhận thanh toán
- Ghi khi khoản phải thu vừa được thu đủ: thanh toán chuyển sang `PAID`, hoặc `PARTIALLY_REFUNDED` từ chưa thu/thu một phần khi đơn
  có bồi hoàn (`so_tien_giam_tru > 0`) và đã thu ≥ khoản phải thu.
- Snapshot (`receiptVersion` 2) tại thời điểm thu: dòng món tính tiền, `itemsSubtotal`, `deliveryFee` (`phi_giao_hang`), `compensation`
  (`so_tien_giam_tru`), `otherAdjustment` (phần chênh còn lại để các dòng luôn khớp), `total` (= `tong_tien − so_tien_giam_tru`),
  `amountPaid`, `amountRefunded`. Không sửa ngược snapshot.
- Hoàn tiền/bồi hoàn/hủy đơn sau lúc thu: biên nhận vẫn gửi (là chứng từ lịch sử), kèm mục "Cập nhật tính đến …" đọc từ sổ tiền và câu
  "Biên nhận phía trên giữ nguyên số liệu tại thời điểm thu tiền". Bồi hoàn là một phần của số đã hoàn, không cộng hai lần. Không tạo chứng từ mới.

### 8.6. Chat hỗ trợ (`SupportChatService`)
- Một hội thoại hoạt động/khách (`active_account_id` duy nhất), một người phụ trách. Trạng thái `AI → WAITING → HUMAN → CLOSED`;
  `release` đưa `HUMAN → WAITING`; về hàng chờ không bao giờ bật lại AI.
- Chỉ người phụ trách trả lời. Nhả/chuyển/tiếp quản bắt buộc lý do ≥ 5 ký tự và ghi `NHAT_KY_THAO_TAC`; người nhận mới phải là STAFF/ADMIN
  đang hoạt động (khóa dòng tài khoản khi kiểm). Khóa nhân viên → hội thoại người đó phụ trách về `WAITING`. Không tự nhả vì mất mạng hay "hết ca".
- Mọi chuyển trạng thái chạy dưới `lockForUpdate`, thử lại tối đa 3 lần khi CSDL báo tranh chấp; hết lượt → 409.
- Chống gửi trùng: `(conversation_id, sender, client_key)` duy nhất + `request_hash`. Con trỏ đã đọc theo ID tin (đơn điệu).

### 8.7. Trợ lý AI (`GeminiAssistant`, `GenerateSupportReply`)
- Xử lý cục bộ trước, không gọi Gemini, không tính quota: yêu cầu nhạy cảm (hủy, hoàn tiền, khiếu nại, dị ứng, gặp nhân viên…) →
  chuyển nhân viên; tra cứu đơn/lượt đặt của **chính** khách. Hết quota Gemini vẫn tra cứu được.
- Chỉ giữ chỗ quota (tài khoản 40/ngày, toàn app 500/ngày — số lượt gọi) ngay trước khi gửi request, sau khi kiểm lại hội thoại vẫn
  `AI`; kiểm tra và trừ cả hai hạn mức trong cùng khóa `ai-daily-budget`.
- Câu hỏi chờ quá `stale_after_minutes` (10) → chuyển nhân viên, không gọi Gemini. Request đã gửi không hủy được; câu trả lời đến sau khi
  nhân viên tiếp nhận bị bỏ dưới khóa hội thoại.
- JSON bị cắt (`finishReason = MAX_TOKENS`), bị chặn, sai schema, timeout, 429/5xx → câu trả lời dự phòng + chuyển nhân viên; log mã lỗi
  (`AI_TRUNCATED`, `AI_PROVIDER_QUOTA`…), HTTP status — không log key, request, nội dung chat hay phản hồi nhà cung cấp.
- Thẻ món chỉ gồm món đang bán công khai, lọc theo dị ứng hồ sơ; giá lấy lại từ DB khi hiển thị.

### 8.8. Giới hạn thao tác
Khách: chat 12/phút, đánh giá 12/phút (bucket riêng). Nhân viên: 60/phút. Gửi lại email của quản trị: 10/phút. Cấu hình
`SUPPORT_CUSTOMER_PER_MINUTE`, `SUPPORT_STAFF_PER_MINUTE`, `EMAIL_RETRY_PER_MINUTE`.
