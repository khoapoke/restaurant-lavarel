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


