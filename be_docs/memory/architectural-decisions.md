# 🏛️ QUYẾT ĐỊNH KIẾN TRÚC HỆ THỐNG (ARCHITECTURAL DECISIONS)

Tài liệu này lưu trữ các quyết định về kiến trúc, cấu trúc code và logic nghiệp vụ đã thống nhất cho dự án `restaurant-lavarel`.

---

## ADR-001: Khởi tạo Cấu trúc Backend Pure RESTful API
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Dự án chỉ đóng vai trò Backend API (phục vụ Client ngoài như React/NextJS), không sử dụng Blade views hoặc frontend bundling của Laravel.
- **Quyết định:**
  - Không chỉnh sửa hay thêm logic vào `resources/views`, `resources/css`, `resources/js`, `routes/web.php`.
  - Khởi tạo 12 Eloquent Models ánh xạ vào 12 bảng trong database `QL_NHAHANG_CNJAVA_V2` (SQL Server).
  - Tắt `$timestamps = false` trên cả 12 Models để tránh lỗi cột `created_at` / `updated_at`.
  - Sử dụng String-Backed Enums trong PHP 8.1+ cho các trạng thái để lưu tiếng Việt xuống DB nhưng trả tiếng Anh ra API JSON.
  - Sử dụng `routes/api.php` với prefix `/api/v1/`.

## ADR-002: Cấu hình Kết nối MySQL, Migration 12 Bảng & Seeder Dữ liệu Mẫu
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Cần môi trường cơ sở dữ liệu MySQL cục bộ để kiểm thử API và logic xử lý nghiệp vụ.
- **Quyết định:**
  - Thiết lập database `restaurant_laravel` trên MySQL (Laragon, port 3306, user `root`, charset `utf8mb4_unicode_ci`).
  - Viết migration `2026_10_19_060000_create_restaurant_system_tables.php` tạo đủ 12 bảng theo đúng chuẩn schema kèm khóa ngoại, khóa chính phức hợp (`MON_AN_GIO_HANG`, `CHI_TIET_DON_HANG`), cột JSON mở rộng (`allergens`, `dietaryPreferences`, `weatherTags`...).
  - Xuất migration `personal_access_tokens` cho Laravel Sanctum.

## ADR-003: Triển khai API Đăng ký Tài khoản Khách hàng (USR-01)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai endpoint `POST /api/v1/auth/register` tuân thủ đúng định dạng JSON envelopes và luồng giao dịch nghiệp vụ.
- **Quyết định:**
  - `ApiResponse` Trait: Chuẩn hóa envelope `{"success", "code", "message", "data"}` hoặc `{"success", "code", "message", "errors"}`.
  - `bootstrap/app.php`: Đăng ký render JSON cho `ValidationException` (HTTP 422) và `AuthenticationException` (HTTP 401) khi request đến `api/*`.
  - `RegisterRequest`: Validate `username` (unique), `email` (unique), `phoneNumber` (regex SĐT VN), `passwordConfirmation` (same).
  - `AuthService::register()`: Thực thi bên trong `DB::transaction()` tuần tự: tạo `UserProfile` (`NGUOI_DUNG`) -> gán role `CUSTOMER` -> tạo `Account` (`TAI_KHOAN`, mã hóa BCrypt) -> tạo `Cart` (`GIO_HANG`) 1-1 -> cấp Sanctum Token `createToken()`.
  - `AccountResource` & `UserProfileResource`: Chuẩn hóa dữ liệu trả về theo đúng mẫu đặc tả trong tài liệu.

## ADR-004: Triển khai API Đăng nhập Hệ thống (USR-02) & Đăng xuất (USR-03)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai `POST /api/v1/auth/login` và `POST /api/v1/auth/logout` theo thuật toán chuyên sâu tại tầng L3.
- **Quyết định:**
  - `LoginRequest`: Validate `username` (required), `password` (required), `rememberMe` (boolean nullable).
  - `AuthService::login()`:
    1. Tìm tài khoản kèm `role`, `userProfile`, `cart`. Nếu không thấy -> ném `AuthenticationException(401, 'Invalid credentials')`.
    2. Kiểm tra mật khẩu: Nếu là BCrypt (`$2y$`, `$2a$`, `$2b$`) -> dùng `Hash::check()`. Nếu là plaintext mẫu (`'123'`) -> kiểm tra `hash_equals()` và tự động băm `Hash::make()` cập nhật xuống DB ngay lần đầu.
    3. Kiểm tra trạng thái khóa: Nếu tài khoản bị khóa (`AccountStatus::LOCKED`) -> ném `HttpException(403, 'Your account has been locked by Administrator')`.
    4. Cấp Sanctum Personal Access Token kèm thời gian hết hạn (`rememberMe ? 30 ngày : 8 giờ`) và ability theo role (`role:admin` hoặc `role:customer`).
  - `AuthService::logout()`: Thu hồi token hiện tại `$account->currentAccessToken()->delete()`.

## ADR-005: Hoàn thiện Middleware Pipeline & Lá chắn Global Exception Shielding cho API
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Khi gọi các endpoint bảo vệ (như `POST /api/v1/auth/logout`) mà không truyền token và thiếu header `Accept: application/json`, Laravel cố gắng redirect về `route('login')` (chưa được định nghĩa trong API), dẫn đến lỗi 500 RouteNotFoundException và làm lộ file stack trace.
- **Quyết định:**
  - **Tổ chức Middleware Pipeline:**
    1. Cấu hình `$middleware->redirectGuestsTo(fn () => null);` triệt tiêu hoàn toàn hành vi redirect về web login.
    2. Tạo middleware `ForceJsonResponse` đưa vào đầu group `api` (`$middleware->prependToGroup('api', ForceJsonResponse::class);`) để luôn ép header `Accept: application/json` cho mọi request API.
    3. Tạo và đăng ký các middleware nghiệp vụ theo đặc tả: `account.active` (`CheckAccountActive::class`) kiểm tra khóa tài khoản và thu hồi token tức thì (403), `role` (`CheckRole::class`) kiểm tra phân quyền người dùng (403).
  - **Cấu hình Global Exception Handler trong `bootstrap/app.php`:**
    - `AuthenticationException` & `RouteNotFoundException`: Luôn trả về HTTP 401 `{ "success": false, "code": 401, "message": "Unauthenticated or token is missing/invalid", "errors": null }`.
    - `ValidationException`: Chuẩn hóa HTTP 422 `{ "success": false, "code": 422, "message": "...", "errors": {...} }`.
    - `ModelNotFoundException`: Chuẩn hóa HTTP 404 `{ "success": false, "code": 404, "message": "Resource not found", "errors": null }`.
    - `HttpException`: Giữ nguyên mã status nghiệp vụ (400, 403, 409).
    - `Throwable` fallback: Bọc toàn bộ ngoại lệ hệ thống không mong muốn thành JSON HTTP 500 chuẩn, tuyệt đối không xuất HTML hay debug stack trace nhạy cảm ra ngoài API.

## ADR-006: Triển khai API Xem Hồ sơ Khách hàng (USR-03)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai endpoint `GET /api/v1/customer/profile` thuộc Phân hệ Khách hàng (Phần A.1).
- **Quyết định:**
  - Route: `GET /api/v1/customer/profile` được bảo vệ bởi bộ 3 middleware: `auth:sanctum`, `account.active`, `role:CUSTOMER`.
  - Controller: `App\Http\Controllers\Api\Customer\ProfileController@show`.
  - Resource: `CustomerProfileResource` nạp dữ liệu từ quan hệ `$account->userProfile`, chuẩn hóa danh sách `savedAddresses` thành mảng object có cấu trúc `{addressId, recipientName, recipientPhone, fullAddress, isDefault}`, xuất đúng mảng `allergies` và `dietaryPreferences`.

## ADR-007: Triển khai API Cập nhật Hồ sơ, Địa chỉ & Dị ứng (USR-04, USR-05, USR-06)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai endpoint `PUT /api/v1/customer/profile` cho phép khách hàng cập nhật đồng thời thông tin cá nhân (`ho_ten`, `ngay_sinh`, `so_dien_thoai`, `email`), sổ địa chỉ nhận hàng (`savedAddresses`) và danh mục chất dị ứng / chế độ ăn (`allergies`, `dietaryPreferences`).
- **Quyết định:**
  - Route: `PUT /api/v1/customer/profile` trong group `customer` với middleware `auth:sanctum`, `account.active`, `role:CUSTOMER`.
  - FormRequest `UpdateProfileRequest`: Áp dụng `Rule::unique('TAI_KHOAN', 'email')->ignore($accountId, 'ma_tai_khoan')` cho phép giữ nguyên email cũ hoặc đổi email mới chưa ai dùng, validate regex số điện thoại Việt Nam.
  - Tầng nghiệp vụ `ProfileService::updateProfile()`: Bọc trong `DB::transaction()` cập nhật song song bảng `TAI_KHOAN` và `NGUOI_DUNG`.
  - Eloquent Model `UserProfile`: Đồng bộ cơ chế serialization mảng JSON `di_ung`, `che_do_an`, `so_dia_chi` qua `$casts = ['array']` để tránh xung đột double-encode.

## ADR-008: Triển khai Quản lý Người dùng dành cho Admin (USR-07 đến USR-10)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai các API phân hệ Quản trị viên (Phần B.1) bao gồm xem danh sách, tìm kiếm & phân trang tài khoản (`USR-07`, `USR-08`), khóa tài khoản kèm thu hồi phiên (`USR-09`) và mở khóa tài khoản (`USR-10`).
- **Quyết định:**
  - **Bảo mật & Phân quyền:** Route group `/api/v1/admin` được bảo vệ bởi middleware `auth:sanctum`, `account.active`, và `role:ADMIN` (chặn toàn bộ người dùng role khác bằng HTTP 403 Forbidden).
  - **Định dạng dữ liệu (`AdminAccountResource`):** Chuẩn hóa output JSON envelope chứa `accountId`, `username`, `email`, `status` ("ACTIVE" / "LOCKED"), `roleName`, `fullName`, `phoneNumber`. Mật khẩu được ẩn hoàn toàn theo `$hidden = ['mat_khau']`.
  - **Tìm kiếm & Phân trang (`AccountService::getAccounts`):**
    - Hỗ trợ tham số `keyword`: Tìm kiếm mờ đồng thời trên `ten_dang_nhap`, `email` và quan hệ `userProfile` (`ho_ten`, `so_dien_thoai`).
    - Lọc theo `status` ("ACTIVE" / "LOCKED") và `roleId`.
    - Phân trang với `items` và `pagination: { currentPage, perPage, totalItems, totalPages }`.
  - **Khóa tài khoản (`AccountService::lockAccount` - USR-09):**
    - Áp dụng **Rule AUTH-LOCK**: Kiểm tra nếu `$currentAdmin->ma_tai_khoan === $accountId` thì ném `HttpException(400, 'Admin cannot lock their own account')`.
    - Cập nhật trạng thái `AccountStatus::LOCKED`.
    - Gọi `$targetAccount->tokens()->delete()` xóa sạch token trong `personal_access_tokens` để cưỡng chế đăng xuất người dùng khỏi mọi phiên làm việc.
  - **Mở khóa tài khoản (`AccountService::unlockAccount` - USR-10):**
    - Cập nhật trạng thái `AccountStatus::ACTIVE`.

## ADR-009: Triển khai Thực đơn, Tìm kiếm & Lọc Món ăn Khách hàng (MNU-01 đến MNU-03)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai các API phân hệ Thực đơn (Phần A.2) cho phép khách hàng và khách vãng lai xem danh mục (`MNU-01`), duyệt danh sách món ăn phân trang (`MNU-02`), tìm kiếm theo tên món, lọc theo danh mục, khoảng giá, tình trạng còn bán và loại bỏ món chứa chất dị ứng (`MNU-03`).
- **Quyết định:**
  - **Routing:** Các route `/api/v1/customer/categories` và `/api/v1/customer/dishes` là public endpoints (khách vãng lai lẫn người dùng có Bearer Token đều truy cập được).
  - **Tách biệt nhóm route:** Phân tách route `/customer` thành nhóm public (Menu, Dishes) và nhóm bảo vệ bởi `['auth:sanctum', 'account.active', 'role:CUSTOMER']` (Profile, Cart, Orders).
  - **Danh mục (`MNU-01`):** `CategoryResource` trả về `categoryId`, `categoryName`, `description`, `displayOrder`. Chỉ lấy các danh mục chưa bị xóa mềm (`da_xoa = false`) và được phép hiển thị (`hien_thi = true`), sắp xếp theo `thu_tu_hien_thi` ASC.
  - **Món ăn & Bộ lọc (`MNU-02`, `MNU-03`):**
    - `DishResource` chuẩn hóa `dishId`, `dishName`, `price` (float), `description`, `imageUrl`, `categoryId`, `categoryName`, `isAvailable` (bool), `ingredients` (array), `allergens` (array).
    - Validation giá: Nếu `minPrice > maxPrice`, ném `HttpException(400, 'minPrice cannot be greater than maxPrice')`.
    - Lọc dị ứng: Hỗ trợ `excludeAllergens` dạng chuỗi phân tách bằng dấu phẩy, sử dụng `whereJsonDoesntContain('chat_di_ung', $allergen)` để loại bỏ triệt để các món có chứa chất gây dị ứng.
    - Phân trang: Chuẩn hóa envelope `pagination: { currentPage, perPage, totalItems, lastPage }`.

## ADR-010: Triển khai Chi tiết Món (MNU-07), Gợi ý Thời tiết (MNU-04) và Quản lý Giỏ hàng (CRT-01 đến CRT-04, MNU-08)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** 
  - Triển khai các API còn lại của Thực đơn: Chi tiết món ăn (`MNU-07`), Gợi ý món ăn theo thời tiết và tự động loại trừ chất gây dị ứng (`MNU-04`).
  - Triển khai Phân hệ Giỏ hàng (Phần A.3): Thao tác thêm món (`CRT-01` / `MNU-08`), cập nhật số lượng (`CRT-02`), xóa món (`CRT-03`), xem giỏ hàng (`CRT-04`).
  - Thống nhất cơ chế đánh mã: `MNU-05` (Lọc theo giá/tình trạng) và `MNU-06` (Lọc dị ứng) đã được tích hợp làm query parameters của `GET /api/v1/customer/dishes` (`MNU-02`, `MNU-03`); `MNU-08` là hành động thêm vào giỏ hàng (`CRT-01`).
- **Quyết định:**
  - **Chi tiết món ăn (`MNU-07`):** Trả về `DishDetailResource` với quan hệ danh mục con `category: { categoryId, categoryName }`. Nếu món không tồn tại, bị ẩn (`hien_thi = false`) hoặc xóa mềm (`da_xoa = true`), ném `HttpException(404, 'Dish not found or unavailable')`.
  - **Gợi ý món theo Thời tiết & Dị ứng (`MNU-04`):**
    - Xác thực tọa độ qua `WeatherRecommendationRequest` (422: `"Coordinates lat and lon are required"`).
    - Caching 15 phút (`Cache::remember`) theo tọa độ làm tròn và `accountId`.
    - Phân loại điều kiện thời tiết: `>= 32°C` (`HOT_SUNNY`), `<= 22°C` (`RAINY_OR_COLD`), còn lại (`PLEASANT_NORMAL`).
    - Nhận diện người dùng: Sử dụng `$request->user('sanctum')` trên public endpoint để tự động đọc `di_ung` từ `userProfile` của khách hàng và loại bỏ 100% món ăn chứa dị ứng khỏi danh sách gợi ý.
  - **Quản lý Giỏ hàng (`CRT-01` $\rightarrow$ `CRT-04`, `MNU-08`):**
    - Sử dụng quan hệ `BelongsToMany` trên model `Cart` (`$cart->dishes()`) kết hợp `CartItem` extends `Pivot`.
    - Thao tác qua các pivot methods: `$cart->dishes()->attach()`, `$cart->dishes()->updateExistingPivot()`, `$cart->dishes()->detach()`.
    - Tuyệt đối không khởi tạo `CartItem::where(...)->first()->save()` để tránh sinh câu lệnh `UPDATE ... WHERE id = null` do bảng `MON_AN_GIO_HANG` sử dụng khóa chính phức hợp `(ma_gio_hang, ma_mon_an)`.
    - Đồng nhất envelope trả về qua `CartResource` chứa `cartId`, `accountId`, `items`, `totalQuantity`, `estimatedTotalAmount`.

## ADR-011: Triển khai Quản lý Danh mục dành cho Admin (MNU-09 đến MNU-12)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai các API quản lý danh mục thuộc Phân hệ Quản trị viên (Phần B.2): Xem danh sách (`GET /categories`), Thêm mới (`MNU-09`), Sửa (`MNU-10`), Ẩn/Hiện (`MNU-11`) và Xóa mềm (`MNU-12`).
- **Quyết định:**
  - **Phân quyền & Bảo mật:** Toàn bộ route `/api/v1/admin/categories/*` được bảo vệ bởi middleware `auth:sanctum`, `account.active`, `role:ADMIN` (trả về HTTP 403 Forbidden đối với Khách hàng/Nhân viên).
  - **Định dạng dữ liệu (`AdminCategoryResource`):** Trả về `{ categoryId, categoryName, description, displayOrder, isVisible, isDeleted, dishesCount }`.
  - **Thêm mới (`CategoryService::createCategory` - MNU-09):** `CreateCategoryRequest` validate `categoryName` bắt buộc, khởi tạo `hien_thi = isVisible ?? true`, `da_xoa = false`, trả về HTTP 201 Created.
  - **Chỉnh sửa (`CategoryService::updateCategory` - MNU-10):** Tìm bản ghi chưa xóa mềm (`da_xoa = false`), cập nhật các trường và trả về HTTP 200 OK (ném HTTP 404 nếu không tìm thấy).
  - **Ẩn/Hiện (`CategoryService::updateVisibility` - MNU-11):** Cập nhật `hien_thi` theo giá trị `isVisible` hoặc đảo ngược trạng thái toggle. Khi danh mục bị ẩn, API khách hàng (`/customer/categories`) tự động loại bỏ danh mục này.
  - **Xóa mềm (`CategoryService::deleteCategory` - MNU-12):** Tuyệt đối không gọi `$category->delete()` vật lý để tránh vi phạm khóa ngoại trong bảng `MON_AN`. Thay vào đó, cập nhật `da_xoa = true, hien_thi = false`, đồng thời tự động ẩn (`hien_thi = false`) các món ăn thuộc danh mục đó.

## ADR-012: Triển khai Quản lý Món ăn dành cho Admin (MNU-13 đến MNU-17)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai các API quản lý món ăn thuộc Phân hệ Quản trị viên (Phần B.2): Xem danh sách/chi tiết (`GET /dishes`, `GET /dishes/{dishId}`), Thêm món mới (`MNU-13`), Chỉnh sửa món (`MNU-14`), Bật/Tắt còn bán (`MNU-15`), Ẩn/Hiện món (`MNU-16`) và Xóa mềm món (`MNU-17`).
- **Quyết định:**
  - **Phân quyền & Bảo mật:** Toàn bộ route `/api/v1/admin/dishes/*` được nhóm và bảo vệ bởi middleware `auth:sanctum`, `account.active`, `role:ADMIN` (trả về HTTP 403 Forbidden nếu không phải Admin).
  - **Khắc phục lỗi Double JSON Serialization trên Model `Dish`:** Loại bỏ `json_encode` thủ công trong các Mutator `ingredients`, `allergens`, `weatherTags` do Eloquent model đã khai báo `$casts` mảng (`'ingredients' => 'array'`), tránh việc bị mã hóa JSON 2 lần thành chuỗi escaped trong MySQL.
  - **Định dạng dữ liệu (`AdminDishResource`):** Trả về đầy đủ thuộc tính quản trị dạng camelCase: `{ dishId, dishName, price, description, imageUrl, categoryId, categoryName, ingredients, allergens, weatherTags, isAvailable, isVisible, isDeleted }`.
  - **Thêm món mới (`MNU-13`):** Validate dữ liệu qua `CreateDishRequest`, tùy biến thông báo lỗi `"The selected category id does not exist in DANH_MUC."` khi `categoryId` không hợp lệ (HTTP 422). Mặc định khởi tạo `isAvailable = true`, `isVisible = true`, `da_xoa = false`, trả về HTTP 201 Created.
  - **Chỉnh sửa món (`MNU-14`):** Cập nhật linh hoạt các trường tên, giá, mô tả, ảnh, danh mục, nguyên liệu, dị ứng, thời tiết qua `UpdateDishRequest`, trả về HTTP 200 OK.
  - **Bật/Tắt còn bán (`MNU-15`):** Cho phép truyền `isAvailable` (boolean) hoặc toggle tự động, cập nhật cột `con_ban`, trả về HTTP 200 OK.
  - **Ẩn/Hiện món ăn (`MNU-16`):** Cho phép truyền `isVisible` (boolean) hoặc toggle tự động, cập nhật cột `hien_thi`. Khi món bị ẩn, API phía khách hàng lập tức không nhìn thấy món này (HTTP 404).
  - **Xóa mềm món ăn (`MNU-17`):** Nghiêm cấm dùng `$dish->delete()` xóa vật lý. Thay vào đó đánh dấu `da_xoa = true, hien_thi = false, con_ban = false` để đảm bảo toàn vẹn dữ liệu cho các bảng có khóa ngoại trỏ tới như `CHI_TIET_DON_HANG` và `MON_AN_GIO_HANG`. Sau khi xóa mềm, các truy vấn quản trị thông thường và phía khách hàng đều trả về HTTP 404.

## ADR-013: Triển khai Tạo đơn hàng & Checkout (ORD-01 đến ORD-03)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai API Tạo đơn hàng Checkout thuộc Phân hệ Khách hàng (Phần A.4): `POST /api/v1/customer/orders/checkout` xử lý các hình thức nhận hàng Giao tận nơi (`ORD-01 - DELIVERY`), Đến lấy tại quán (`ORD-02 - PICKUP`), và Phương thức thanh toán (`ORD-03 - CASH hoặc BANK_TRANSFER_QR`).
- **Quyết định:**
  - **Bảo toàn Giao dịch Toàn vẹn (`DB::transaction`):** Toàn bộ quy trình checkout từ khóa giỏ hàng (`lockForUpdate`), kiểm tra tình trạng món, snapshot đơn giá, tạo đơn hàng `DON_HANG`, chèn `CHI_TIET_DON_HANG`, khởi tạo `THANH_TOAN` và làm rỗng `MON_AN_GIO_HANG` được thực hiện nguyên khối trong một database transaction duy nhất.
  - **Khóa & Kiểm tra Giỏ hàng:** Khóa giỏ hàng bằng `lockForUpdate()` nhằm chống race condition (double-click checkout). Báo lỗi HTTP 400 `"Cannot checkout an empty cart"` nếu giỏ trống.
  - **Bảo vệ Món ăn Còn bán & Snapshot Đơn giá:** Kiểm tra trạng thái từng món trong giỏ (`isAvailable`, `isVisible`, `!isDeleted`). Nếu món bị tắt bán hoặc hết hàng trước lúc bấm đặt, hủy giao dịch và trả về HTTP 400 `"Dish '{dishName}' has just sold out"`. Đơn giá được chốt cứng (`snapshot`) tại thời điểm đặt hàng lưu vào cột `don_gia` của `CHI_TIET_DON_HANG`.
  - **Chuẩn hóa Chuỗi Địa chỉ (`dia_chi_giao_hang`):**
    - `DELIVERY`: `"[DELIVERY] {recipientName} - {recipientPhone} | {shippingAddress}"` kèm ghi chú `| Note: {orderNote}` nếu có.
    - `PICKUP`: `"[PICKUP] Nhận tại nhà hàng - KH: {recipientName} - SĐT: {recipientPhone}"` kèm ghi chú `| Note: {orderNote}` nếu có.
  - **Khởi tạo Thanh toán (`THANH_TOAN`):** Tạo bản ghi thanh toán 1-1 với đơn hàng với `so_tien = tong_tien`, `trang_thai = PaymentStatus::UNPAID`, `phuong_thuc = PaymentMethod::CASH` hoặc `PaymentMethod::BANK_TRANSFER_QR`.
  - **Định dạng dữ liệu (`OrderResource`):** Trả về đầy đủ thông tin chuẩn hóa theo API specifications: `orderId`, `orderDate`, `status` (`OrderStatus::PENDING_CONFIRMATION` -> `"PENDING_CONFIRMATION"`), `totalAmount`, `shippingAddress`, `payment` (`OrderPaymentResource`), `items` (`OrderItemResource`).

## ADR-014: Triển khai Lịch sử Đơn hàng, Chi tiết & Hủy đơn Khách hàng (ORD-04 đến ORD-06)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai các API quản lý vòng đời đơn hàng phía Khách hàng (Phần A.4): Xem danh sách lịch sử đơn (`ORD-04`), Xem chi tiết đơn (`ORD-05`), và Khách yêu cầu hủy đơn hàng (`ORD-06`).
- **Quyết định:**
  - **Bảo mật Phạm vi Truy cập (Chống lỗ hổng IDOR):** Mọi truy vấn lịch sử (`GET /orders`), chi tiết (`GET /orders/{orderId}`) và hủy đơn (`POST /orders/{orderId}/cancel`) đều bắt buộc kèm điều kiện `where('ma_tai_khoan', $accountId)`. Nếu khách hàng cố ý truy cập hoặc thao tác trên mã đơn hàng của người khác, hệ thống đồng nhất ném lỗi HTTP 404 `"Order not found"`.
  - **Lịch sử Đơn hàng (`ORD-04`):** Hỗ trợ lọc theo trạng thái `status` (nhận cả mã Enum tiếng Anh như `COMPLETED` lẫn giá trị tiếng Việt như `'Đã giao'`), phân trang `perPage`, sắp xếp đơn mới nhất lên đầu (`orderByDesc('ma_don_hang')`), eager load quan hệ `dishes` và `payment` để tối ưu N+1 query.
  - **Chi tiết Đơn hàng (`ORD-05`):** Sử dụng `OrderResource` chuẩn hóa toàn bộ dữ liệu đơn hàng, thanh toán kèm danh sách món chi tiết.
  - **Quy tắc Máy Trạng thái khi Hủy đơn (`ORD-06`):**
    - Khách hàng chỉ được phép hủy khi đơn hàng đang ở trạng thái `OrderStatus::PENDING_CONFIRMATION` (`N'Chờ xác nhận'`).
    - Nếu đơn đã được xác nhận, đang nấu, sẵn sàng hoặc đã giao (`CONFIRMED`, `PREPARING`, `READY`, `COMPLETED`, hoặc đã `CANCELLED`), hệ thống chặn và trả về HTTP 409 Conflict với thông điệp: `"Order cannot be cancelled because it is already being prepared by the kitchen"`.
    - Khi hủy thành công: Cập nhật `trang_thai = OrderStatus::CANCELLED`, đồng thời lưu `cancelReason` vào cột `ly_do_hoan_tien` trong bảng `THANH_TOAN` nếu có. Trả về HTTP 200 OK kèm payload `{ orderId, status: "CANCELLED" }`.

## ADR-015: Triển khai Thanh toán VietQR, Tra cứu Trạng thái, Thử lại & Webhook Callback (PAY-01 đến PAY-03)
- **Ngày quyết định:** 2026-10-19
- **Bối cảnh:** Triển khai nhóm API Thanh toán QR thuộc Phân hệ Khách hàng (Phần A.4): Sinh mã VietQR (`PAY-01`), Tra cứu trạng thái thanh toán (`PAY-02`), Thử lại thanh toán (`PAY-03`), và Webhook tiếp nhận kết quả thanh toán từ Cổng (`POST /api/v1/webhooks/payment-callback`).
- **Quyết định:**
  - **Tích hợp chuẩn VietQR:** Cấu hình chuẩn định dạng ảnh VietQR (`https://img.vietqr.io/image/{bankBin}-{accountNo}-{template}.png?amount={amount}&addInfo={content}`). Nội dung chuyển khoản chuẩn hóa: `"DH{orderId} THANH TOAN"`.
  - **Quy tắc Nghiệp vụ Sinh mã & Thử lại (`PAY-01`, `PAY-03`):**
    - Kiểm tra quyền sở hữu đơn hàng (chống IDOR).
    - Nếu đơn hàng đã hủy (`CANCELLED`), chặn thao tác với HTTP 400 `"Order has already been cancelled"`.
    - Nếu đơn hàng đã thanh toán thành công (`PaymentStatus::PAID`), chặn và trả về HTTP 409 Conflict `"Order has already been paid"`.
    - Thử lại thanh toán (`retry`): Reset trạng thái về `UNPAID`, cập nhật mã giao dịch mới và gia hạn thời gian thanh toán `expiresAt` thêm 15 phút.
  - **Tra cứu Trạng thái (`PAY-02`):** Trả về trạng thái giao dịch (`UNPAID`, `PAID`, `FAILED`, `REFUNDED`), số tiền, mã giao dịch và cờ `isPaid` (boolean).
  - **Xử lý Webhook Callback (`/api/v1/webhooks/payment-callback`):**
    - Tự động nhận diện `orderId` từ trường dữ liệu hoặc trích xuất từ cú pháp nội dung chuyển khoản (`DH\d+`).
    - Kiểm tra số tiền thực nhận so với số tiền cần thanh toán của đơn hàng.
    - Cập nhật `THANH_TOAN.trang_thai = PaymentStatus::PAID`, lưu log callback `log_callback` và cập nhật `ma_giao_dich`.
    - Tự động chuyển trạng thái đơn hàng `DON_HANG.trang_thai` sang `CONFIRMED` nếu đang ở `PENDING_CONFIRMATION`.

## ADR-016: Triển khai Đặt bàn Trực tuyến Khách hàng (BKG-01 đến BKG-04)
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** Triển khai các API Đặt bàn Trực tuyến phía Khách hàng (Phần A.5): Tra cứu bàn trống (`BKG-01`), Tạo lượt đặt bàn (`BKG-02`), Sửa lượt đặt bàn (`BKG-03`), và Hủy lượt đặt bàn (`BKG-04`).
- **Quyết định:**
  - **Tra cứu Bàn trống (`BKG-01`):** Công khai (`Public - Guest & Customer`). Tìm kiếm theo `date`, `time`, `guestCount`. Lọc các bàn có `suc_chua >= guestCount`, trạng thái `trang_thai != MAINTENANCE`, và loại trừ toàn bộ bàn có xung đột đặt trước trong ca dùng bữa ($\pm 119$ phút) ở trạng thái `PENDING` hoặc `CONFIRMED`.
  - **Kiểm tra Sức chứa & Bảo trì (`BKG-02`, `BKG-03`):** Nghiêm cấm đặt hoặc sửa số người vượt quá sức chứa bàn ăn (`HTTP 422: Guest count exceeds table capacity`). Chặn đặt bàn đang bảo trì (`HTTP 400: Selected table is currently under maintenance`).
  - **Kiểm tra Thời gian Báo trước (Chính sách 1.2.5.a):**
    - Mọi lượt đặt bàn thông thường phải cách thời điểm hiện tại tối thiểu 30 phút.
    - Với các yêu cầu đặc biệt chứa từ khóa (`sinh nhật`, `phòng riêng`, `vip`, `chay`, `trang trí`), thời gian đặt bàn bắt buộc phải cách hiện tại tối thiểu 120 phút (02 giờ). Vi phạm trả về `HTTP 400: Special requests require at least 2 hours advance notice.`.
  - **Chống Xung đột Trùng Khung giờ ($\pm 119$ phút):** Bàn ăn tại thời điểm $T$ được coi là đã có người đặt nếu có bản ghi `DAT_BAN` khác của bàn đó nằm trong khoảng $[T - 119', T + 119']$ với trạng thái `PENDING` hoặc `CONFIRMED` (`HTTP 409: Table is already reserved for the selected time slot`).
  - **Điều kiện Sửa hoặc Hủy Đặt bàn (Chính sách 1.2.5.b):**
    - Khách hàng chỉ được phép sửa (`BKG-03`) hoặc hủy (`BKG-04`) khi thời gian đến thời điểm hẹn còn tối thiểu 60 phút (`now()->diffInMinutes(thoi_gian, false) >= 60`).
    - Nếu dưới 60 phút, hệ thống từ chối với `HTTP 409 Conflict: Cannot modify or cancel reservation less than 60 minutes before scheduled time`.
    - Bảo vệ IDOR: Chỉ chủ sở hữu lượt đặt (`ma_tai_khoan = $accountId`) mới được xem, sửa hoặc hủy.

## ADR-017: Triển khai Quản lý Đơn hàng, Máy Trạng thái & Duyệt đơn Admin (ORD-07 đến ORD-12)
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** Triển khai các API Quản lý Đơn hàng thuộc Phân hệ Quản trị viên (Phần B.3): Xem danh sách đơn (`ORD-07`), Xem chi tiết đơn, Chuyển trạng thái đơn hàng tuần tự (`ORD-08` $\rightarrow$ `ORD-11`), và Admin hủy đơn hàng kèm lý do (`ORD-12`).
- **Quyết định:**
  - **Phân quyền & Kiểm soát truy cập:** Toàn bộ route `/api/v1/admin/orders/*` được bảo vệ bởi middleware `['auth:sanctum', 'account.active', 'role:ADMIN']`. Mọi truy cập từ Khách hàng (`ROLE:CUSTOMER`) hoặc tài khoản không có quyền đều bị từ chối với `HTTP 403 Forbidden`.
  - **Định dạng dữ liệu chuẩn hóa (`AdminOrderResource` & `AdminPaymentResource`):**
    - Trả về payload camelCase hoàn chỉnh: `{ orderId, orderDate, status, totalAmount, shippingAddress, accountId, customerName, customerPhone, payment, items }`.
    - `payment` nhúng `AdminPaymentResource`: `{ paymentId, amount, paymentMethod, status, transactionCode, refundReason }`.
  - **Danh sách & Bộ lọc (`ORD-07`):** Hỗ trợ lọc theo `status` (nhận diện cả enum key tiếng Anh như `PENDING_CONFIRMATION` lẫn tên tiếng Việt như `Chờ xác nhận`), tìm kiếm `search` theo `ma_don_hang`, `dia_chi_giao_hang`, `ten_dang_nhap`, `ho_ten`, `so_dien_thoai`, phân trang `perPage` và sắp xếp giảm dần theo mã đơn.
  - **Kiểm soát Máy Trạng thái Đơn hàng (`OrderStatus::canTransitionTo`):**
    - Luồng chuyển tiếp hợp lệ tuân thủ DAG đơn hướng: `PENDING_CONFIRMATION` $\rightarrow$ `CONFIRMED` $\rightarrow$ `PREPARING` $\rightarrow$ `READY` $\rightarrow$ `COMPLETED`.
    - Các trạng thái chưa hoàn tất (`PENDING_CONFIRMATION`, `CONFIRMED`, `PREPARING`, `READY`) đều có thể rẽ nhánh sang `CANCELLED`.
    - Hai trạng thái `COMPLETED` và `CANCELLED` là trạng thái kết thúc (terminal states), không thể chuyển sang bất kỳ trạng thái nào khác.
    - Mọi nỗ lực chuyển tiếp vi phạm quy tắc (ví dụ `COMPLETED` $\rightarrow$ `PREPARING` hoặc hủy đơn đã `CANCELLED`) đều bị chặn và trả về `HTTP 400 Bad Request` với thông điệp chính xác: `"Invalid status transition from {previousStatus} to {currentStatus}"`.
  - **Đồng bộ Thanh toán khi Hoàn thành Đơn hàng (`ORD-11`):**
    - Khi Admin chuyển đơn sang `OrderStatus::COMPLETED` (`Đã giao`):
      - Nếu phương thức thanh toán là `CASH` (`Tiền mặt`), hệ thống tự động cập nhật `payment.trang_thai = PaymentStatus::PAID` (`Đã thanh toán`).
      - Nếu phương thức thanh toán là `BANK_TRANSFER_QR` (`Chuyển khoản`) mà `payment.trang_thai` vẫn là `UNPAID` (`Chưa thanh toán`), hệ thống chặn hoàn thành đơn và báo lỗi `HTTP 400 Bad Request: "Đơn hàng chuyển khoản QR chưa được xác nhận thanh toán thành công"`.
  - **Admin Hủy đơn kèm lý do (`ORD-12`):**
    - Endpoint `POST /api/v1/admin/orders/{orderId}/cancel` chấp nhận `cancelReason` (chuỗi tối đa 500 ký tự).
    - Cập nhật `trang_thai = OrderStatus::CANCELLED`, đồng thời lưu vết lý do vào cột `ly_do_hoan_tien` trong bảng `THANH_TOAN`. Trả về `{ orderId, status: "CANCELLED" }`.

## ADR-018: Triển khai Quản lý Thanh toán, Đối soát & Hoàn tiền Admin (PAY-04 đến PAY-07)
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** Triển khai nhóm API Quản lý Giao dịch & Thanh toán thuộc Phân hệ Quản trị viên (Phần B.3.2): Tra cứu danh sách giao dịch (`PAY-04`), Đối soát số tiền thanh toán với đơn hàng (`PAY-05`), Ghi nhận hoàn tiền giao dịch (`PAY-06`), và Xem logs webhook callback (`PAY-07`).
- **Quyết định:**
  - **Phân quyền:** Toàn bộ route `/api/v1/admin/payments/*` được bảo vệ bởi middleware `['auth:sanctum', 'account.active', 'role:ADMIN']` (trả về HTTP 403 Forbidden đối với Khách hàng).
  - **Tra cứu giao dịch (`PAY-04`):**
    - `AdminPaymentResource` trả về thông tin giao dịch chuẩn hóa: `{ paymentId, orderId, amount, paymentMethod, status, transactionCode, refundReason, orderDate, customerName, customerPhone }`.
    - Hỗ trợ lọc theo `status` (`UNPAID`, `PAID`, `REFUNDED`, `FAILED`), phương thức `paymentMethod` (`CASH`, `BANK_TRANSFER_QR`), mã đơn hàng `orderId`, tìm kiếm theo mã giao dịch/khách hàng và phân trang `perPage`.
  - **Đối soát số tiền thanh toán (`PAY-05`):**
    - Hỗ trợ cả 2 chế độ: Đối soát đơn lẻ theo `orderId`/`paymentId` và Đối soát toàn bộ (Batch reconcile).
    - So khớp `THANH_TOAN.so_tien === DON_HANG.tong_tien`. Báo cáo cụ thể `isMatched`, `difference`, `paymentStatus`, `orderStatus`, và chi tiết lỗi `issue` nếu phát hiện sai lệch số tiền hoặc trạng thái bất thường (ví dụ: đơn hoàn thành nhưng thanh toán chưa trả).
  - **Ghi nhận hoàn tiền (`PAY-06`):**
    - Endpoint `POST /api/v1/admin/payments/{paymentId}/refund` yêu cầu `refundReason` (chuỗi từ 3-500 ký tự).
    - **Quy tắc cốt lõi:** Chỉ các giao dịch đã thanh toán thành công (`PaymentStatus::PAID`) mới được phép hoàn tiền. Vi phạm trả về `HTTP 400 Bad Request` với message chính xác: `"Only PAID transactions can be refunded"`.
    - Khi hoàn tiền thành công: Cập nhật `THANH_TOAN.trang_thai = PaymentStatus::REFUNDED`, lưu `ly_do_hoan_tien`, đồng thời tự động cập nhật trạng thái đơn hàng liên kết sang `OrderStatus::CANCELLED`. Trả về payload `{ paymentId, orderId, amount, status: "REFUNDED", refundReason }`.
  - **Tra cứu Logs Webhook Callback (`PAY-07`):**
    - Endpoint `GET /api/v1/admin/payments/callback-logs` trả về danh sách các giao dịch có `log_callback IS NOT NULL`.
    - Tự động parse chuỗi JSON lưu vết trong database thành đối tượng có cấu trúc (`CallbackLogResource`), hỗ trợ lọc log lỗi `onlyErrors=true` và phân trang.

## ADR-019: Triển khai Quản lý Bàn ăn, Cấu hình Khung giờ & Điều phối Đặt bàn Admin (BKG-05 đến BKG-12)
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** Triển khai nhóm API Quản lý Bàn ăn, Khung giờ hoạt động và Điều phối lượt đặt bàn thuộc Phân hệ Quản trị viên (Phần B.4): Quản lý bàn ăn & sức chứa (`BKG-05`, `BKG-06`), Cấu hình khung giờ đặt bàn (`BKG-07`), Tra cứu danh sách đặt bàn (`BKG-08`), và Duyệt trạng thái lượt đặt (`BKG-09` $\rightarrow$ `BKG-12`).
- **Quyết định:**
  - **Phân quyền:** Toàn bộ route `/api/v1/admin/tables/*` và `/api/v1/admin/reservations/*` được bảo vệ bởi middleware `['auth:sanctum', 'account.active', 'role:ADMIN']` (từ chối 403 Forbidden nếu không phải Admin).
  - **Quản lý Bàn ăn (`BKG-05`, `BKG-06`):**
    - Các endpoints: `GET/POST /tables`, `GET/PUT/DELETE /tables/{tableId}`.
    - `AdminTableResource` chuẩn hóa thuộc tính: `{ tableId, tableName, capacity, status, location }`.
    - Chặn xóa vật lý bàn ăn nếu bàn đó có các lượt đặt đang hoạt động hoặc sắp diễn ra (`PENDING` hoặc `CONFIRMED` trong vòng 120 phút) với lỗi `HTTP 400 Bad Request`.
  - **Cấu hình Khung giờ Đặt bàn (`BKG-07`):**
    - Endpoints: `GET/PUT /api/v1/admin/reservations/time-slots`.
    - Quản lý cấu hình giờ mở/đóng cửa, độ dài ca ăn và danh sách khung giờ phục vụ thông qua `Cache::forever('restaurant_time_slots_config', ...)`.
  - **Danh sách Đặt bàn Quản trị (`BKG-08`):**
    - Endpoint: `GET /api/v1/admin/reservations` hỗ trợ bộ lọc trạng thái `status`, ngày `date`, bàn `tableId`, tìm kiếm khách hàng `search`, phân trang `perPage` và eager load đầy đủ `['diningTable', 'account.userProfile']`.
  - **Điều phối & Đồng bộ Trạng thái Bàn ăn (`BKG-09` $\rightarrow$ `BKG-12`):**
    - Endpoint: `PATCH /api/v1/admin/reservations/{reservationId}/status`.
    - **Xác nhận đặt bàn (`CONFIRMED` - `BKG-09`):**
      - Kiểm tra xung đột trùng giờ: Nếu bàn ăn đó đã có lượt đặt khác ở trạng thái `CONFIRMED` trong khoảng $[\text{thoi\_gian} - 119', \text{thoi\_gian} + 119']$, hệ thống từ chối và trả về `HTTP 409 Conflict: "Table #{tableId} has an overlapping CONFIRMED reservation at this time"`.
      - Khi duyệt thành công: Tự động đồng bộ trạng thái bàn sang `TableStatus::RESERVED` (`Đã đặt`).
    - **Từ chối (`REJECTED` - `BKG-10`), Hủy (`CANCELLED` - `BKG-11`) & Hoàn thành (`COMPLETED` - `BKG-12`):**
      - Tự động giải phóng trạng thái bàn ăn về `TableStatus::AVAILABLE` (`Trống`).
    - **Ghi chú Admin (`adminNote`):** Tự động nối và lưu vết ghi chú quản trị viên vào trường `ghi_chu`.
    - Payload phản hồi chuẩn hóa theo spec: `{ reservationId, status, tableId, tableStatus }`.

## ADR-021: Tích hợp Redis In-Memory Caching & Distributed Locks Cho Hệ Thống
- **Ngày quyết định:** 2026-10-31
- **Bối cảnh:** 
  - Khách hàng và quản trị viên gửi nhiều truy vấn thường xuyên (danh mục món ăn, gợi ý món theo thời tiết) vào MySQL, làm tăng tải database.
  - Khi nhiều khách hàng đồng thời đặt chung một bàn vào cùng khung giờ, hoặc double-click thanh toán/tạo đơn hàng giỏ hàng, xuất hiện nguy cơ Race Condition (Double-booking hoặc Duplicate checkout).
  - Môi trường phát triển: Windows / Laragon (PHP 8.3), MySQL 8.0, Redis 7.2 (Docker container `redis-server` ánh xạ port 6379).
- **Quyết định:**
  - **Sử dụng thư viện `predis/predis` (v3.7):**
    - Chọn package Composer thuần PHP `predis/predis` thay vì yêu cầu cài extension `php_redis.dll` trên Windows/Laragon, giúp đảm bảo tương thích 100% không phụ thuộc C-extension.
    - Cấu hình `.env`:
      ```env
      CACHE_STORE=redis
      REDIS_CLIENT=predis
      REDIS_HOST=127.0.0.1
      REDIS_PORT=6379
      ```
  - **In-Memory Caching (Hiệu năng cao):**
    - `MenuService::getCategories()` (`MNU-01`): Caching danh mục thực đơn đang hoạt động (`menu:categories:active`) trong 3600 giây với cơ chế `hydrate()`.
    - `CategoryService` (`MNU-09` đến `MNU-12`): Tự động xóa cache (`Cache::forget('menu:categories:active')`) khi Admin thêm, sửa, đổi trạng thái ẩn/hiện hoặc xóa mềm danh mục.
    - `MenuService::getWeatherRecommendations()` (`MNU-04`): Lưu cache kết quả gợi ý món ăn theo tọa độ và dị ứng khách hàng (`weather_rec_{lat}_{lon}_{accountId}`) trong 900 giây (15 phút).
  - **Redis Distributed Lock (Chống Race Condition):**
    - `ReservationService::createReservation()` (`BKG-02`): Sử dụng `Cache::lock("lock:reservation:table:{$tableId}", 5)` với `block(3)` để tuần tự hóa các yêu cầu đặt cùng một bàn ăn, ngăn chặn triệt để tình trạng hai khách hàng đặt trùng cùng một bàn trong khung giờ $\pm 119$ phút. Trả về `HTTP 409 Conflict` nếu quá thời gian chờ khóa.
    - `OrderService::checkout()` (`ORD-01`): Sử dụng `Cache::lock("lock:order:checkout:{$accountId}", 10)` với `block(5)` để chặn các thao tác double-click submit đơn hàng từ frontend, bảo vệ giỏ hàng và dữ liệu thanh toán.

## ADR-022: Tái Cấu Trúc Hệ Thống Theo Kiến Trúc Modular Monolith (Module-based Architecture)
- **Ngày quyết định:** 2026-11-10
- **Bối cảnh:**
  - Sau khi hoàn thành toàn bộ 59 API endpoints của hệ thống và tích hợp Redis, toàn bộ codebase đang nằm phân tán theo tầng kỹ thuật truyền thống (`app/Http/Controllers/Api/`, `app/Services/`, `app/Models/`, `app/Http/Requests/`).
  - Khi quy mô dự án tăng lên, việc gom tất cả controller hay model vào một thư mục chung làm tăng độ phức tạp trong việc quản lý mã nguồn và ranh giới nghiệp vụ (Coupling).
  - Cần tái cấu trúc sang mô hình **Modular Monolith** phân rã theo Domain/Module mà vẫn đảm bảo 100% giữ nguyên API Contract cho Client.
- **Quyết định:**
  - **Phân rã thành 7 Module Nghiệp vụ + 1 Core Module:**
    1. `Auth`: Xác thực, Tài khoản, Hồ sơ người dùng, Phân quyền RBAC.
    2. `Menu`: Danh mục, Món ăn, Gợi ý thời tiết (Redis Cache).
    3. `Cart`: Giỏ hàng mua sắm khách hàng.
    4. `Order`: Đơn hàng, Trạng thái FSM, Chi tiết đơn (Redis Lock).
    5. `Reservation`: Bàn ăn, Đặt bàn, Xử lý xung đột lịch hẹn (Redis Lock).
    6. `Payment`: Giao dịch thanh toán, VietQR Napas 247, Webhook.
    7. `Report`: Báo cáo doanh thu, đơn hàng, món bán chạy, dashboard.
    8. `Core` / `Shared`: `ApiResponse` Trait, Backed Enums, Middlewares, Exceptions, Migrations.
  - **Tổ chức tài liệu đặc tả & theo dõi:**
    - Khởi tạo `OVERVIEW.md` dành cho người dùng và khách hàng đọc hiểu giá trị dự án.
    - Cập nhật `README.md` chuyên biệt dành cho kỹ sư phần mềm / reviewer kỹ thuật.
    - Tạo `be_docs/api/README.md` chuẩn hóa giao thức phản hồi (Envelopes), mã lỗi HTTP và cơ chế che chắn ngoại lệ.
    - Tạo `be_docs/modules.md` đóng vai trò là bản đồ kiến trúc module và bảng theo dõi tiến độ hoàn thành các module.

## ADR-024: Tái Cấu Trúc Tài Liệu Nghiệp Vụ Chuẩn Software Architect (Module-Centric Use Cases)
- **Ngày quyết định:** 2026-11-13
- **Bối cảnh:**
  - Trước đây, tài liệu nghiệp vụ tầng L3 (`business_logic_and_validation_rules.md`) được viết dưới dạng tài liệu nguyên khối (Monolithic Doc), trong khi đặc tả API lại nằm riêng ở `be_docs/api/*.md`.
  - Sự phân tán này khiến nhà phát triển và AI phải tra cứu nhiều file rời rạc khi triển khai một tính năng hoặc API cụ thể.
  - Dự án cần một cấu trúc tài liệu cấp doanh nghiệp (Enterprise-grade) theo chuẩn Software Architect: kết hợp "What" (API Protocol / Contract), "How" (Business Rules / Algorithms / FSM) và "Why" (Domain Logic / Constraints) vào một điểm tra cứu duy nhất cho mỗi Module.
- **Quyết định:**
  - **Tổ chức thư mục `be_docs/modules/`:** Mỗi module nghiệp vụ sở hữu một tệp markdown độc lập tích hợp toàn diện:
    1. `be_docs/modules/auth.md` (11 Use Cases)
    2. `be_docs/modules/menu.md` (14 Use Cases)
    3. `be_docs/modules/cart.md` (5 Use Cases)
    4. `be_docs/modules/order.md` (7 Use Cases + FSM)
    5. `be_docs/modules/reservation.md` (11 Use Cases + 2 FSM)
    6. `be_docs/modules/payment.md` (7 Use Cases)
    7. `be_docs/modules/report.md` (5 Use Cases)
    8. `be_docs/modules/recommendation.md` (3 Use Cases + Neo4j Graph)
  - **Cấu trúc 4 phần chuẩn hóa cho từng module:**
    1. *Tổng quan & Trách nhiệm Bounded Context.*
    2. *Thành phần kỹ thuật (Models, Services, Controllers, Requests, Resources, Routes).*
    3. *Domain Rules (Redis Lock, Cache, Soft Delete, State Transition matrix).*
    4. *Use Cases chi tiết (Actor, Precondition, Validation Rules, Thuật toán từng bước, Flowchart/State Diagram, JSON Envelope mẫu, Alt Flow & Error Codes).*
  - **Tối ưu hóa OpenAPI Schema & Giảm tải Token (Streamlined Edition):**
    - Áp dụng nguyên tắc Schema-first: Định nghĩa JSON Resource mẫu cho Frontend ở đầu mỗi nhóm, gom các API CRUD đơn giản thành bảng 1 dòng.
    - Giữ nguyên 100% chi tiết thuật toán, FSM và Distributed Lock cho các Use Case phức tạp.
    - Giảm 80% dung lượng thư mục `modules/` (từ 268 KB xuống 52 KB) ngăn chặn hoàn toàn nguy cơ cạn kiệt token/burn out cho AI.
  - **Tài liệu quy ước cốt lõi:** Khởi tạo `be_docs/system_conventions.md` đóng vai trò là bản tra cứu nhanh 98 dòng (Cheat Sheet) cho các quy tắc bất biến.
  - **Chỉ mục tổng thể:** Cập nhật `be_docs/README.md` làm cửa ngõ duy nhất. Xóa bỏ các tệp trùng lặp (`laravel_restful_api_specifications.md`, `business_logic_and_validation_rules.md`, `be_docs/api/`).
  - **Chuẩn hóa Thuật ngữ Kỹ thuật:** Phân biệt tường minh giữa **"Luồng thực thi" (Execution Flow)** cho các nghiệp vụ transaction/CRUD/FSM thông thường (`auth`, `menu`, `order`, `reservation`, `payment`) và **"Thuật toán" (Algorithm)** dành riêng cho giải thuật tính toán khoa học máy tính (`recommendation` - Neo4j Graph Cypher Traversal, Collaborative Filtering).
















