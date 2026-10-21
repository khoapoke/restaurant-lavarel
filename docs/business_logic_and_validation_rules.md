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
    SET $cacheKey = "weather_" . round($lat, 2) . "_" . round($lon, 2);
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
- Visit chỉ thao tác được khi `trang_thai = ACTIVE` **và** `ma_phien` = phiên đang mở của bàn. Phiên kết thúc → visit chỉ còn quyền đọc hóa đơn trong `visit_readable_hours`.
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
