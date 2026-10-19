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








