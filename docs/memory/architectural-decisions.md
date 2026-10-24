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
  - **Dữ liệu nhận hàng có cấu trúc:** Theo ADR-018, `DELIVERY` lưu địa chỉ thuần; `PICKUP` để địa chỉ giao hàng rỗng và có thể lưu lịch hẹn lấy. Người nhận, điện thoại, ghi chú, loại phục vụ và trạng thái bàn giao được lưu ở các cột riêng.
  - **Khởi tạo Thanh toán (`THANH_TOAN`):** Tạo bản ghi thanh toán 1-1 với đơn hàng với `so_tien = tong_tien`, `trang_thai = PaymentStatus::UNPAID`, `phuong_thuc = PaymentMethod::CASH` hoặc `PaymentMethod::BANK_TRANSFER_QR`.
  - **Định dạng dữ liệu (`OrderResource`):** Trả về đầy đủ thông tin chuẩn hóa theo API specifications, bao gồm trạng thái đơn, loại/trạng thái phục vụ, người nhận, địa chỉ hoặc lịch lấy, tổng món, phí giao hàng, tổng cộng, thanh toán và danh sách món.

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

## ADR-016: Triển khai Đặt bàn trực tuyến cho Khách hàng
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** MVP cần cho khách chủ động tìm bàn, gửi yêu cầu đặt chỗ, xem lịch, chỉnh sửa và hủy đặt chỗ thay vì chỉ liên hệ thủ công với nhà hàng.
- **Quyết định:**
  - Các endpoint `/api/v1/customer/reservations/*` được bảo vệ bởi `auth:sanctum`, `account.active`, `role:CUSTOMER`; mọi truy vấn chi tiết đều giới hạn theo tài khoản hiện tại để chống IDOR.
  - Việc tìm bàn dựa trên sức chứa, trạng thái bảo trì và khoảng thời gian xung đột `±119 phút`; khi tạo/cập nhật sử dụng transaction cùng `lockForUpdate()` để hạn chế hai khách nhận cùng một bàn.
  - Yêu cầu thông thường phải gửi trước tối thiểu 30 phút; yêu cầu đặc biệt (sinh nhật, kỷ niệm, trang trí, bánh) phải gửi trước tối thiểu 120 phút. Khách chỉ được đổi hoặc hủy trước giờ đến ít nhất 60 phút.
  - Vòng đời đặt chỗ được kiểm soát bởi `ReservationStatus::canTransitionTo()`; đặt chỗ mới bắt đầu ở `PENDING`, khách hủy chuyển sang `CANCELLED`, còn xác nhận/phục vụ/hoàn tất do nhân viên quản trị xử lý.

## ADR-017: Triển khai Trung tâm Vận hành Admin cho MVP
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** Giao diện quản trị cũ chỉ là khung minh họa, chưa đủ để nhà hàng xử lý đơn, đặt chỗ, bàn, thanh toán và theo dõi hoạt động thực tế.
- **Quyết định:**
  - Toàn bộ API vận hành nằm trong `/api/v1/admin` và được bảo vệ bởi `auth:sanctum`, `account.active`, `role:ADMIN`.
  - Đơn hàng tuân theo máy trạng thái tại `OrderStatus::canTransitionTo()`; hủy đơn yêu cầu lý do. Đơn tiền mặt được ghi nhận đã thanh toán khi hoàn tất, còn đơn QR chỉ hoàn tất sau khi thanh toán thành công.
  - Hoàn tiền chỉ áp dụng cho giao dịch `PAID`; đối soát thanh toán cập nhật trạng thái theo dữ liệu giao dịch và lưu callback để truy vết.
  - Cập nhật bàn bị chặn nếu làm sức chứa nhỏ hơn số khách đang phục vụ hoặc chuyển bàn đang có phiên POS sang bảo trì. Trạng thái bàn được đồng bộ với đặt chỗ và phiên phục vụ.
  - Dashboard lấy dữ liệu thật từ đơn hàng, thanh toán, đặt chỗ, phiên POS và bàn; doanh thu chỉ tính các giao dịch `PAID`, không dùng số liệu mock ở frontend.

## ADR-018: Tách loại phục vụ, tiến độ bếp và trạng thái bàn giao
- **Ngày quyết định:** 2026-10-20
- **Bối cảnh:** Một trạng thái đơn hàng duy nhất không mô tả đúng ba nghiệp vụ giao tận nơi, khách tự đến lấy và ăn tại chỗ; cách ghép người nhận/điện thoại/ghi chú vào chuỗi địa chỉ cũng gây khó lọc và báo cáo.
- **Quyết định:**
  - Checkout online chỉ cho phép `DELIVERY` và `PICKUP`. `DINE_IN` được tạo từ phiên phục vụ POS khi khách đã đến nhà hàng; đặt bàn vẫn là nghiệp vụ riêng và không tự tạo đơn món.
  - `DON_HANG` lưu riêng `loai_phuc_vu`, `trang_thai_giao_nhan`, người nhận, số điện thoại, địa chỉ, thời gian nhận dự kiến và phí giao hàng. Không tiếp tục dùng tiền tố `[DELIVERY]`/`[PICKUP]` trong `dia_chi_giao_hang`.
  - `OrderStatus` quản lý tiến độ bếp đến `READY`; `FulfillmentStatus` quản lý bàn giao: giao hàng đi qua `AWAITING_DRIVER → OUT_FOR_DELIVERY → DELIVERED`, tự lấy đi qua `AWAITING_CUSTOMER → COLLECTED`, tại bàn đi qua `SERVING → SERVED`.
  - `READY` tự khởi tạo trạng thái chờ bàn giao phù hợp. Chỉ trạng thái giao nhận cuối mới chuyển đơn sang `COMPLETED`; không cho chuyển trực tiếp `READY → COMPLETED`.
  - Đơn QR phải thanh toán trước khi giao cho tài xế hoặc hoàn tất bàn giao. Đơn tiền mặt được ghi nhận thanh toán tại thời điểm `DELIVERED`/`COLLECTED`; POS tại bàn chốt qua luồng thanh toán phiên phục vụ.

## ADR-019: Hoàn thiện vận hành MVP — liên kết đặt bàn với POS, KDS hợp nhất, bảo mật thanh toán
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** Rà soát end-to-end cho thấy: bàn `RESERVED` không mở được phiên và đặt bàn không gắn với phiên phục vụ; món của đơn online (kể cả chưa xác nhận/đã hủy) lọt vào KDS và có thể đẩy đơn qua `READY` mà bỏ qua bàn giao; admin có thể đổi trạng thái/hủy đơn `DINE_IN` ngoài POS và đặt tay `OCCUPIED`/`RESERVED`; webhook thanh toán không kiểm chữ ký; ứng dụng chạy múi giờ UTC trong khi giờ đặt bàn là giờ Việt Nam.
- **Quyết định:**
  - Thêm cột `PHIEN_PHUC_VU.ma_dat_ban` (FK `DAT_BAN`, `nullOnDelete`) và `ly_do_huy` (migration `2026_10_20_170606`, idempotent). Mở phiên nhận `reservationId`: lượt đặt phải `CONFIRMED`, đúng bàn, trong hôm nay, chưa nhận bàn. Bàn có lượt `CONFIRMED` trong khung ±119 phút chỉ mở được cho đúng lượt đó. Đóng phiên → lượt đặt `COMPLETED`.
  - `TableStatusSynchronizer` là nơi duy nhất quyết định `AVAILABLE ↔ RESERVED` theo lượt đặt `CONFIRMED` trong ngày; dùng chung cho admin duyệt/hủy, khách sửa/hủy, hủy phiên, vệ sinh xong. Admin chỉ đặt tay `AVAILABLE`, `CLEANING`, `MAINTENANCE`.
  - POS: hủy phiên mở nhầm khi chưa có món gửi bếp; `reopen` hủy (`VOID`) hóa đơn chưa trả để gọi thêm món, lập lại hóa đơn tính lại tổng trên cùng mã; đơn `READY` vẫn nhận thêm món; thanh toán QR tại bàn lưu `transactionCode`, hóa đơn mở trả mã VietQR `HD{id}`.
  - KDS hiển thị món POS và món đơn online đã xác nhận; khóa đơn (`lockForUpdate`) trước khi khóa món để tránh race khi nhiều món cùng hoàn tất. Đơn online: món đầu tiên bắt đầu → `PREPARING`, tất cả `READY` → `READY` + trạng thái chờ bàn giao; không `SERVED` ở bếp. Thao tác trạng thái đơn của admin đồng bộ ngược trạng thái món.
  - Admin không điều khiển đơn `DINE_IN`; không hủy đơn đang `OUT_FOR_DELIVERY`. `AdminOrderResource.availableActions` là nguồn duy nhất cho nút thao tác trên frontend.
  - Thanh toán: `POST /admin/payments/{id}/confirm` để xác nhận chuyển khoản thủ công (lưu vết người xác nhận). Webhook bắt buộc HMAC-SHA256 (`PAYMENT_WEBHOOK_SECRET`), bắt buộc số tiền, idempotent, có transaction + khóa; tiền về cho đơn đã hủy ghi `PAID` kèm `requiresRefund`. Cấu hình VietQR chuyển sang `config('services.vietqr')` qua `VietQrService` (không gọi `env()` trong service).
  - `config('app.timezone')` mặc định `Asia/Ho_Chi_Minh` (`APP_TIMEZONE`). Dữ liệu cũ tạo khi chạy UTC sẽ hiển thị lệch 7 giờ (chỉ ảnh hưởng dữ liệu demo cũ).
  - Rate limit: đăng ký 10/phút, đăng nhập 20/phút, webhook 60/phút. Dashboard bổ sung cơ cấu doanh thu, tiền hoàn, việc cần xử lý.

## ADR-020: Mộc Live Dining — gọi món QR tại bàn trên nền POS hiện có
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** Cần một trải nghiệm trình diễn xuyên suốt: khách quét QR tại bàn, xin nhận bàn, gọi món, theo dõi bếp, gọi nhân viên và xem hóa đơn — không cần tài khoản, không tạo hệ thống đơn thứ hai và không mở rộng thêm module CRUD.
- **Quyết định:**
  - **Hai lớp token:** `BAN_AN.ma_qr` (40 ký tự ngẫu nhiên, có thể tạo lại) chỉ định danh bàn; `LUOT_KHACH_BAN` lưu SHA-256 của visit token 64 ký tự cho từng thiết bị, gắn với bàn và phiên. Không endpoint công khai nào nhận `tableId`/`sessionId`. Visit hết quyền thao tác khi phiên đóng/hủy.
  - **Check-in là hàng đợi vận hành**, không phải CRUD: `PENDING → ACTIVE/REJECTED/CANCELLED/EXPIRED`. Chấp nhận luôn đi qua `PosSessionService::open` để giữ nguyên quy tắc đặt bàn, sức chứa và chống mở trùng phiên.
  - **Lượt gọi món = `PHIEU_BEP`** (thêm `nguon`, `ma_luot`, `idempotency_key` unique, `request_hash`). Món QR nối vào đơn POS duy nhất của phiên với trạng thái `SENT`, nên KDS, hóa đơn, thanh toán, báo cáo dùng lại toàn bộ luồng ADR-019. Giá luôn lấy từ `MON_AN` và snapshot vào chi tiết đơn.
  - **Yêu cầu phục vụ** (`YEU_CAU_PHUC_VU`): `NEW → ACKNOWLEDGED → COMPLETED/CANCELLED`, chống trùng + cooldown + rate limit; yêu cầu thanh toán được ưu tiên và tự đồng bộ theo vòng đời hóa đơn.
  - Webhook nhận thêm nội dung `HD{invoiceId}` để thanh toán hóa đơn tại bàn qua `BillingService::pay`; không có thao tác nào phía khách đánh dấu đã trả tiền.
  - Endpoint công khai có rate limit riêng (`dine-read/entry/order/service`) theo token bàn + thiết bị + IP. Lệnh `php artisan dining:demo` chỉ chạy ngoài production, chỉ đụng tới bàn demo.
  - Schema mới chỉ gồm: `BAN_AN.ma_qr/ma_qr_tao_luc`, `LUOT_KHACH_BAN`, `YEU_CAU_PHUC_VU`, 4 cột của `PHIEU_BEP`, `CHI_TIET_DON_HANG.bep_cap_nhat_luc` (migration `2026_10_21_094545`, idempotent, có `down`).

## ADR-021: Cập nhật live bằng polling thông minh thay vì WebSocket
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** Dự án chưa cài Laravel Reverb/Pusher, `BROADCAST_CONNECTION=log`, `QUEUE_CONNECTION=sync`, chạy bằng `php artisan serve` + SQLite. Thêm WebSocket đòi hỏi tiến trình Reverb, cấu hình CORS/WS và hàng đợi — dễ hỏng khi demo trên máy khác.
- **Quyết định:**
  - **Dùng polling, không WebSocket.** Trang khách 2,5 giây, hàng đợi POS 3 giây, KDS 3 giây, sơ đồ bàn 8 giây, phiên POS 4 giây.
  - API khách trả `version` (băm snapshot); client gửi `since=version`, server trả `{changed:false}` khi không đổi → không tải lại payload.
  - Hook `useLivePolling` (frontend): chuỗi `setTimeout` nên không có request chồng nhau, `AbortController` khi unmount/refresh, giãn chu kỳ khi tab ẩn, đồng bộ ngay khi tab hiện lại/online, backoff 2× khi lỗi mạng với trạng thái `reconnecting/offline` hiển thị cho người dùng.
  - Tài liệu và giao diện ghi rõ "đồng bộ mỗi N giây", không tuyên bố realtime WebSocket. Khi cần mở rộng có thể phát sự kiện Reverb từ các service hiện có mà không đổi API.

## ADR-022: Mã bàn theo phiên, gửi món bền vững qua reload và giới hạn lệnh demo
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** Review milestone Live Dining tái hiện ba lỗi: (1) `/join` công khai cấp visit mới cho bất kỳ ai cầm QR in cố định — kể cả khách bữa trước, không cần pass cũ — và nhánh nâng visit `PENDING` cho phép đặt sẵn yêu cầu rồi lọt vào phiên nhân viên mở tại POS; (2) frontend giữ `Idempotency-Key` trong bộ nhớ, mất phản hồi + reload khiến lần gửi lại mang key mới và backend (đúng thiết kế) tạo lượt thứ hai; (3) `dining:demo` hủy cả phiên ghép Bàn L01 với bàn thường, để bàn thường `OCCUPIED` mà không còn phiên.
- **Quyết định:**
  - **QR chỉ nhận diện bàn.** Mỗi phiên có `PHIEN_PHUC_VU.ma_tham_gia` (4 chữ số, sinh khi tạo phiên, đổi theo từng phiên, không mass-assign). Thiết bị chưa thuộc phiên — không pass, pass phiên cũ, hay yêu cầu `PENDING` — phải gửi đúng mã khi `join`; chỉ nhân viên chấp nhận check-in mới gắn thiết bị không cần mã. Thiết bị đã thuộc phiên dùng tiếp bình thường.
  - Mã sai được đếm theo phiên (`so_lan_sai_ma`); đủ `DINE_JOIN_CODE_MAX_FAILURES` (5) thì khóa (`423`) đến khi nhân viên tạo mã mới (`POST /staff/live/sessions/{id}/join-code`). Request không kèm mã không bị tính là lần đoán. Kết hợp rate limit `dine-entry` 8/phút.
  - Mã chỉ trả cho thiết bị đã ở trong phiên (`session.joinCode`) và cho nhân viên (POS). Khách trong bàn mời bạn bằng mã hoặc link `/dine/{token}#code=XXXX` (mã ở fragment nên không tới server/log và bị xóa khỏi URL ngay).
  - **Gửi món bền vững:** trước khi gọi API, frontend ghi lượt (key + payload đã chốt + dấu vết thiết bị) vào `localStorage` và tách khỏi giỏ. Sau reload/tab khác, lượt treo được tự gửi lại đúng key và payload; backend trả lại lượt cũ (`created:false`) kể cả khi phiên đã sang `AWAITING_PAYMENT`. Chỉ xóa khi biết chắc: 2xx, 4xx nghiệp vụ (đã rollback → trả món về giỏ), hoặc 409 “key đã dùng với nội dung khác”. Lỗi mạng/5xx/429 giữ nguyên lượt.
  - **Demo chỉ đụng bàn demo:** `LiveDiningDemoService` kiểm tra mọi phiên trước khi ghi; phiên nào có bàn khác Bàn L01 thì từ chối toàn bộ lệnh, không thay đổi gì.
  - Migration bổ sung `2026_10_21_105139_add_join_code_to_phien_phuc_vu_table` (cấp mã cho phiên đang mở hiện có).

## ADR-023: Sổ giao dịch tiền thật, phân bổ và ghi nhận hoàn tiền
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** Kiểm toán 89 case (PAY-03/06/07/09/11/13/14/16) cho thấy `THANH_TOAN` 1–1 với đơn, không thời điểm, không người thao tác, `ma_giao_dich` không unique và `log_callback` bị ghi đè: tiền thứ hai, tiền dư, tiền cho hóa đơn cũ hoặc đơn đã hủy biến mất; báo cáo gom theo ngày đặt đơn.
- **Quyết định:**
  - **`GIAO_DICH_TIEN`**: mỗi khoản tiền thật một dòng (nguồn WEBHOOK/MANUAL/POS/COD/LEGACY, nhà cung cấp, tài khoản nhận, mã tham chiếu, số tiền, đã phân bổ, đã hoàn, nội dung, đích đơn/hóa đơn/phiên bản, `nhan_luc` ≠ `ghi_nhan_luc`, người ghi nhận, payload gốc, trạng thái đối soát `MATCHED/NEEDS_REVIEW/RESOLVED`, cờ `OVERPAID/UNDERPAID/DUPLICATE/ORDER_CANCELLED/INVOICE_STALE/SESSION_CLOSED/UNKNOWN_TARGET/NO_REFERENCE`). Khóa duy nhất `UPPER(provider|account|reference)` + `idempotency_key`.
  - **`PHAN_BO_THANH_TOAN`**: áp tiền vào đơn/hóa đơn/phiên bản; dòng âm `REFUND_REVERSAL` khi hoàn phần đã áp. Đã thu của đơn = tổng phân bổ. Không phân bổ quá số còn thiếu; phần dư ở lại giao dịch (chưa phân bổ).
  - **`HOAN_TIEN`**: ghi nhận đã hoàn (ADMIN, lý do, phương thức, mã chứng từ, thời điểm hoàn ≠ thời điểm ghi). Không vượt đã nhận − đã hoàn. Hệ thống không chuyển tiền thật.
  - **`HOA_DON_PHIEN_BAN`** + `HOA_DON.phien_ban`: lập lại sau VOID tăng phiên bản; nội dung VietQR `HD{id}V{n} THANH TOAN`; `HD{id}` cũ chỉ khớp bản 1; không tái dùng bản VOID.
  - `THANH_TOAN` thành **bản tổng hợp** (`so_tien_da_thu`, `so_tien_da_hoan`, `thanh_toan_luc`, trạng thái `PARTIALLY_PAID/PARTIALLY_REFUNDED`), luôn tính lại từ sổ (`PaymentLedgerService::syncOrderPayment`).
  - Webhook có chữ ký luôn được **lưu** kể cả không áp được; trả 200 với `APPLIED/DUPLICATE_CALLBACK/RECORDED_FOR_REVIEW`. Xác nhận thủ công và POS gộp với callback cùng mã thay vì ghi trùng.
  - Dung sai 0. Thu thiếu → thanh toán một phần, thu bù được (tiền mặt ≤ còn thiếu, `cashTendered` để tính tiền thối).
  - Báo cáo tách: giá trị bán (ngày đặt), tiền nhận (ngày tiền về), hoàn (ngày hoàn), chưa phân bổ, phải hoàn, khách còn nợ, hủy/miễn/làm lại/thất thu, tiền cũ không rõ ngày. Khóa dashboard cũ giữ tương thích (`totalPaidRevenue` = tiền đã áp vào đơn bán).
  - Backfill (migration `2026_10_21_121710`): mỗi thanh toán PAID/REFUNDED cũ → một giao dịch `LEGACY` + phân bổ; `nhan_luc` lấy từ `HOA_DON.thanh_toan_luc` nếu có, không thì để trống (báo cáo tách riêng); không bịa người/ngày, không nhân đôi số dư.

## ADR-024: Sửa món sau khi gửi bếp, yêu cầu duyệt và nhật ký thao tác
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** KDS-01/02/03/04/05/07/11, SES-09, AUTH-03, AUD-01: món đã gửi không hủy được, bếp không từ chối được (phiên kẹt), không có làm lại/đổi/tách/hoàn tác, chỉ hai mức quyền, không có lịch sử thao tác.
- **Quyết định:**
  - `CHI_TIET_DON_HANG` thêm số phần tính tiền `so_luong_tinh_tien`, loại dòng `NORMAL/REMAKE/EXCHANGE` + dòng gốc, lý do hủy (enum + ghi chú), cờ miễn tiền, người/thời điểm hủy/miễn, trạng thái bếp trước đó và người cập nhật. Tiền dòng = đơn giá × số phần tính tiền; món thuộc hóa đơn khi số phần tính tiền > 0, kể cả đã hủy (khách đổi ý sau khi nấu).
  - `ItemCorrectionService`: hủy (SENT/QUEUED: STAFF; đã nấu: chỉ ADMIN), miễn tiền (ADMIN), bếp từ chối (+ báo hết món), làm lại (giá 0, phiếu bếp mới), đổi món (tại bàn), tách dòng. Mọi thay đổi tính lại tổng, đồng bộ trạng thái đơn, lập lại hóa đơn mở (VOID bản cũ), đồng bộ sổ tiền. Hoàn tác bước bếp ≤ 120 giây nếu chưa có bước phụ thuộc.
  - **`YEU_CAU_DUYET`** (`ITEM_VOID`, `SEAT_OUTSIDE_WINDOW`, `SESSION_WRITE_OFF`): STAFF gửi, ADMIN duyệt/từ chối bằng chính phiên đăng nhập; khi duyệt, thao tác chạy trong cùng transaction; lỗi nghiệp vụ giữ yêu cầu ở PENDING. Không có ô mật khẩu quản lý trên máy nhân viên.
  - Thất thu: chỉ ADMIN; hóa đơn `WRITTEN_OFF`, phiên `CLOSED` với `kieu_ket_thuc=WRITE_OFF`, `so_tien_that_thu`, lý do, người duyệt; món chưa nấu tự hủy `NOT_SERVED`; không bao giờ ghi PAID.
  - **`NHAT_KY_THAO_TAC`**: người, vai trò, thời điểm, hành động, đối tượng, lý do, trước/sau; lọc **đệ quy** khóa nhạy cảm (mật khẩu, token, chữ ký, mã bàn, QR). Chỉ đọc trong ứng dụng; xem theo phiên POS, đơn, lượt đặt.

## ADR-025: Đặt bàn theo khoảng thời gian thật, giữ chỗ, nhận khách và điều chỉnh phiên
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** RSV-01→19, SES-02/04/05/06/08: hằng 119 phút dùng cho bốn mục đích, không có hết hạn/no-show/gia hạn, nhận khách bất kỳ giờ nào trong ngày, không đổi bàn/số khách, bàn đang có khách vẫn được đặt.
- **Quyết định:**
  - `ReservationSchedule` là nguồn duy nhất: thời lượng 90/120, dọn 15, nhận sớm 30, giữ 20, gia hạn ≤ 30, no-show tự động 60; PENDING hết hiệu lực tại giờ hẹn, CONFIRMED hết giữ chỗ sau hạn — kiểm tra trực tiếp ở API, không phụ thuộc lệnh quét.
  - Phiên có `du_kien_ket_thuc_luc`; phiên quá giờ coi như chiếm thêm 60 phút khi tính bàn trống. Khách vãng lai được báo giờ phải trả bàn (lượt kế tiếp − 15 phút) và cần còn ≥ 30 phút.
  - `DAT_BAN` thêm `ghi_chu_noi_bo` (chuyển dòng `[ADMIN]` cũ sang), gia hạn (đến, lý do, người), xử lý (lúc, người, lý do); trạng thái `EXPIRED`, `NO_SHOW`; `COMPLETED` chỉ từ đóng phiên.
  - Mở phiên: cửa sổ nhận khách (ngoài cửa sổ: ADMIN + lý do), nhận ở bàn khác (lý do), ghép bàn, nhường bàn sau hạn giữ (ghi NO_SHOW + mở phiên trong một transaction). Phiên: đổi số khách, chuyển/ghép bàn (bàn cũ CLEANING/AVAILABLE/MAINTENANCE); thẻ QR đi theo phiên (`GuestAccessService::sessionCoversTable`, `movedTo`).
  - Trùng lịch của cùng một khách so khoảng dùng bàn thuần (không cộng thời gian dọn).
  - Lệnh `dining:reservations:sweep` mỗi 5 phút, `withoutOverlapping`.

## ADR-026: Ngoại lệ giao hàng và tự đến lấy
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** DLV-01→10, KDS-12: đơn đang giao không thể thất bại/hủy, đổi CASH→QR làm đơn kẹt, không sửa người nhận, không mã nhận hàng, đơn hẹn xuống bếp ngay.
- **Quyết định:**
  - `DELIVERY_FAILED` (nguyên nhân enum + mô tả + thời điểm) → `AWAITING_DRIVER` (giao lại, đếm `so_lan_giao`) hoặc hủy. Tiền mặt chỉ ghi sổ (`COD`) khi bàn giao thành công.
  - Hủy đơn lưu lý do/người/thời điểm và quyết định tiền `REFUND_DUE` (mặc định) / `RETAINED` (chỉ khi khách không đến lấy quá hạn hoặc từ chối/không liên lạc được). Đang giao phải ghi kết quả trước khi hủy.
  - Sửa người nhận/địa chỉ đến trước khi giao tài xế (khách và admin, nhật ký trước/sau). Phương thức thanh toán khóa từ khi xác nhận.
  - Tự lấy: `ma_nhan_hang` 4 số (sinh khi tạo đơn, không trả cho màn quản trị), bàn giao khi đúng mã hoặc SĐT, ghi người lấy hộ; giữ món 60 phút sau max(giờ sẵn sàng, giờ hẹn).
  - Đơn hẹn: giờ hẹn trong giờ nhận đơn, ≤ 7 ngày; xuống KDS từ giờ hẹn − 30 phút; KDS có danh sách "sắp tới". Đặt ngay (ASAP) chỉ trong giờ nhận đơn.

## ADR-027: Thực đơn nhất quán, xác nhận lại khi giá đổi, giờ nhận gọi món và ma trận quyền
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** MNU-01/02, QR-07, RSV-16, AUTH-02/03.
- **Quyết định:**
  - Món bán được khi món chưa xóa, đang hiện, còn bán **và** danh mục đang hiện, chưa xóa (`Dish::publiclyListed()`, `isOrderable()`), áp ở menu, giỏ, checkout, POS, QR. Ẩn/hiện/xóa danh mục không ghi đè cờ của từng món.
  - Checkout và lượt QR gửi `expectedTotal`; lệch → 409 kèm `errors.reason=PRICE_CHANGED`, tổng mới và đơn giá mới (`DomainConflictException`). Lượt QR đã nhận vẫn được trả lại khi gửi lại cùng khóa (kiểm tra giá sau kiểm tra replay). Request không kèm `expectedTotal` vẫn được chấp nhận để tương thích (frontend luôn gửi).
  - `OpeningHours`: gọi món mới 10:00–22:00 (POS gửi bếp, QR, mở phiên, đơn ASAP); giờ khách đến 10:00–21:30. Phiên đang mở vẫn thanh toán/đóng sau giờ.
  - Ma trận quyền ở `docs/operations/exception-policies.md` §7, kiểm tra trong service/route (không chỉ ẩn nút).

## ADR-028: Định danh chuyển khoản, bồi hoàn, gửi lại an toàn và các lối ra còn thiếu (review A–N)
- **Ngày quyết định:** 2026-10-21
- **Bối cảnh:** Review độc lập (A–N) tái hiện được 14 lỗi sau milestone ngoại lệ: cùng một khoản chuyển ghi hai lần
  vì khóa dùng nhãn nguồn; webhook trỏ tới đích không tồn tại gây 500; ghi hoàn/thu tiền gửi lại bị ghi lặp;
  hoàn một phần sau thanh toán làm POS kẹt; phiên mọi món bị từ chối không có lối đóng; API bếp bỏ qua giờ xuống bếp
  của đơn hẹn; báo cáo món bán chạy đếm cả phần làm lại 0 ₫; gia hạn giữ chỗ không thấy khách vãng lai; quyền đọc
  của khách sau chuyển bàn; cache gợi ý theo dị ứng; checkout QR mất modal; tương phản chữ trên nền rượu vang.
- **Quyết định:**
  - **Định danh chuyển khoản** (`App\Services\Payments\TransferIdentity`): `BANK:{ngân hàng}|{tài khoản nhận}|{mã}`.
    Tài khoản đã cấu hình luôn quy về ngân hàng đã cấu hình; nhãn nguồn chung (`payments.generic_providers`) không
    phải ngân hàng; bí danh ngân hàng chuẩn hóa qua `payments.bank_aliases`. Migration `2026_10_21_224546` tính lại
    khóa cũ; dòng trùng sau chuẩn hóa giữ khóa `…#DUP{id}`, gắn `DUPLICATE`/`NEEDS_REVIEW` — **không gộp tiền tự động**.
  - Đích đơn/hóa đơn của giao dịch chỉ được gán khi tồn tại; không thì `UNKNOWN_TARGET` (chứng từ không bao giờ mất).
  - **Hoàn và bồi hoàn** (`HOAN_TIEN.loai_hoan`): `REFUND` ≤ phần nhà hàng đang nợ khách (`refundDue`); phần vượt trên
    đơn còn hiệu lực chỉ được ghi dưới dạng `COMPENSATION`, cộng vào `DON_HANG.so_tien_giam_tru`; khoản phải thu =
    `tong_tien − so_tien_giam_tru`. Chọn cách này thay vì để trạng thái thanh toán tụt về `PARTIALLY_PAID` khi hóa đơn
    `PAID` (mâu thuẫn làm POS kẹt). Đây là thay đổi hợp đồng có chủ đích: request cũ không kèm `refundType` cho phần
    vượt → 422 với thông báo hướng dẫn.
  - **Gửi lại an toàn**: `HOAN_TIEN.khoa_yeu_cau` (từ `Idempotency-Key` hoặc chứng từ theo đơn/giao dịch) gom mọi dòng
    của một yêu cầu; gửi lại cùng nội dung trả các dòng cũ, khác nội dung → 409. Thu tiền POS: cùng khóa khác nội dung
    → 409; frontend lưu khóa + nội dung khoản đang gửi (localStorage), chỉ xóa khi có phản hồi nghiệp vụ.
  - **Kết thúc phiên không tính tiền** (`POST /staff/pos/sessions/{id}/finish-without-charge`, `SessionEndType::NO_CHARGE`)
    cho phiên mà mọi món đã xuống bếp đều bị hủy/từ chối: không tạo hóa đơn 0 ₫, không dùng thất thu (không có nợ).
  - **Đơn hẹn giờ**: `Order::kitchenReleaseAt()`; API bếp 409 trước mốc; quản lý làm sớm qua cập nhật trạng thái đơn
    với `reason` bắt buộc, nhật ký `ORDER_STARTED_EARLY`.
  - Món bán chạy theo phần tính tiền (`billedAmount`), tách `preparedQuantity`/`freeQuantity`; gia hạn giữ chỗ từ chối
    khi bàn có phiên chồng khoảng mới; quyền đọc của khách theo phiên chứa bàn; gợi ý chỉ cache thời tiết.
- **Hệ quả:** Không còn trạng thái "hóa đơn PAID nhưng đơn còn thiếu". Đối soát thủ công cần xử lý các dòng
  `#DUP` mà migration gắn cờ (DB dev có 8 dòng như vậy từ dữ liệu demo dùng lại một mã). Khóa dòng thật trên
  MySQL/SQL Server chưa được kiểm (test chạy SQLite).

### ADR-028 bổ sung (2026-10-23): ngân hàng nêu rõ thắng số tài khoản; hoàn tiền theo dòng chi tiết từng đơn
- **Định danh:** nguồn nêu rõ ngân hàng (tên/alias/BIN) → dùng ngân hàng đó, kể cả khi dãy số tài khoản trùng tài
  khoản cấu hình (khoản riêng, chuyển đối soát vì thông tin mâu thuẫn). Chỉ nguồn không nêu ngân hàng (nhập tay, POS,
  nhãn VietQR) mới quy về tài khoản nhận đã cấu hình. Cùng mã tham chiếu ở định danh khác mà một bên không đủ
  ngân hàng + tài khoản → không gộp đoán, ghi riêng, `NEEDS_REVIEW` kèm ghi chú.
- **Hoàn tiền:** một yêu cầu hoàn gồm nhiều dòng `HOAN_TIEN` cùng `khoa_yeu_cau`: mỗi đơn bị rút tiền một dòng (gắn
  dòng đảo phân bổ của đơn đó), phần tiền chưa phân bổ một dòng riêng. "Đã hoàn của đơn" = tổng dòng của đơn.
  `PaymentLedgerService::refund()` trả về `Collection<Refund>`.
- **Dữ liệu cũ:** migration mới `2026_10_23_090000` (không sửa migration đã chạy). `down()` không đảo dữ liệu.

## ADR-029: Email + inbox + Mộc đồng hành, không mở rộng CRUD
- **Ngày quyết định:** 2026-10-23.
- Thông báo ghi vào inbox và outbox cùng transaction với sự kiện đặt bàn/đơn/thanh toán. Không SMTP trong transaction nghiệp vụ. Revision + khóa sự kiện duy nhất chống ghi lặp; worker xử lý sau commit trên queue database `communications`. Scheduler quét outbox mỗi phút để chịu được lỗi dispatch.
- Tiếp nhận đặt bàn nói rõ đang chờ duyệt; chỉ CONFIRMED mới giữ chỗ. Email nhắc lịch trước 60 phút được kiểm tra lại khi gửi; thông báo cũ đã bị thay thế không gửi. Biên nhận là chứng từ nhà hàng, không giả làm hóa đơn thuế. Mời đánh giá sau 2 giờ, tối đa 1/ngày/tài khoản, không chiến dịch marketing.
- Trạng thái mail: PENDING/RETRY/SENDING/ACCEPTED/SKIPPED/FAILED; test transport là SIMULATED, không đánh dấu gửi thật. 4xx SMTP tạm thời thử tối đa 3 lần; kết quả không chắc chắn cần quản trị kiểm tra, xác nhận rủi ro và ghi nhật ký trước khi gửi lại. SMTP không đảm bảo exactly-once sau crash.
- Chat xác thực CUSTOMER, truy vấn giới hạn chủ tài khoản. Một hội thoại hoạt động/tài khoản, một nhân viên phụ trách. AI → WAITING → HUMAN → CLOSED; khách được đóng/mở cuộc mới. Idempotency UUID + hash nội dung trong DB và localStorage theo tài khoản bảo vệ retry/reload. Polling giữ đúng ADR-021, không cài Reverb.
- Gemini chỉ backend, JSON schema + giới hạn context/output, timeout, quota lượt gọi theo ngày. Chỉ thực đơn/chính sách công khai gửi sang AI; trạng thái đơn/lượt của chính khách tra cứu nội bộ và loại khỏi lịch sử gửi model. Giá/thẻ món kiểm lại từ DB; không quyền viết giao dịch. Lỗi/quota/cần xử lý nhạy cảm → hàng chờ nhân viên. Trả lời AI đến muộn sau takeover bị bỏ dưới khóa hội thoại.
- Demo chuyển mọi email về `COMMUNICATIONS_TEST_RECIPIENT`, production không dùng override. Đã áp riêng migration communications sau backup SQLite; không chạy migration sửa tiền đang chờ. SMS/Zalo/Web Push vẫn để sau.

### ADR-029 bổ sung (2026-10-24): sửa theo review communications
- **Chuyển người hỗ trợ:** người phụ trách được `release` về `WAITING`; ADMIN `reassign` (STAFF/ADMIN đang hoạt động) hoặc `takeover`.
  Bắt buộc lý do + nhật ký. Khóa nhân viên → hội thoại họ phụ trách về hàng chờ, giữ lịch sử, báo khách. Không tự nhả vì mất mạng/"hết ca".
  Về hàng chờ không bật lại AI. Chuyển trạng thái thử lại khi CSDL báo tranh chấp, hết lượt → 409 (đã kiểm hai tiến trình trên file SQLite).
- **Mời đánh giá:** một lời mời mỗi bữa (bữa tại bàn = phiên POS, online = đơn) và một/ngày/tài khoản, bằng hai khóa duy nhất trong
  `customer_notices` (`event_key`, `limit_key`). Bị giới hạn ngày không ghi gì.
- **Hạn gửi email:** 2 giờ cập nhật, 24 giờ hủy/từ chối/không đến/chat chưa đọc, 48 giờ mời đánh giá, 7 ngày biên nhận, nhắc lịch tới giờ hẹn;
  `SKIPPED/EMAIL_EXPIRED`, không gia hạn khi retry, không xóa inbox/chứng từ.
- **SMTP:** chỉ mã phản hồi 4xx Symfony gắn vào exception mới tự gửi lại; timeout/không rõ/5xx chờ quản trị. Không dò số trong chuỗi lỗi.
- **Biên nhận:** snapshot v2 giải thích tiền món + phí giao − giảm trừ/bồi hoàn = tổng phải thu; cùng hàm dựng dòng cho email và API. Hoàn tiền sau
  lúc thu hiển thị tách riêng có thời điểm, không sửa snapshot, không tạo chứng từ. Biên nhận cũng ghi khi thu đủ sau bồi hoàn (PARTIALLY_REFUNDED).
- **Quota AI:** tra cứu nội bộ/chuyển nhân viên cục bộ không tính quota; chỉ giữ chỗ ngay trước request Gemini. Câu hỏi chờ > 10 phút chuyển nhân viên.
  JSON bị cắt (`MAX_TOKENS`) → dự phòng. Log mã lỗi an toàn.
- **Hàng đợi:** `dispatched_at`/`ai_dispatched_at` + lease 10 phút; hàng đợi tồn quá 2 phút thì scheduler không đẩy thêm. Giới hạn thao tác tách bucket:
  khách 12, nhân viên 60, gửi lại email 10 mỗi phút.
- **Chuyển tiếp schema:** `GuestCommunicationService::schemaReady()` cho code mới chạy khi DB chưa áp migration `2026_10_23_233204`: không ghi cột mới,
  dispatcher tạm dừng với cảnh báo `communications.migration_pending`, nghiệp vụ đặt bàn/đơn không bị rollback. Có thể bỏ sau khi mọi môi trường đã migrate.
- **Frontend:** polling có `resetKey` (đổi trang/bộ lọc/hội thoại tải ngay, bỏ phản hồi cũ); tin chờ gửi lưu theo từng `clientKey`, tự bỏ khi tin đã có
  trên server, dọn khi đăng xuất/401/khóa/đổi tài khoản; đồng bộ phiên giữa các tab; ConfirmDialog thay `window.confirm`.
- **Chưa làm:** observer/revision và `Schema::hasTable` mỗi sự kiện giữ nguyên vì chưa chứng minh được lỗi thực tế.
