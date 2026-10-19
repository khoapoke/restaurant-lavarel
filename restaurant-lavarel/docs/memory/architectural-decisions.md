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
  - Viết `RestaurantSeeder` nạp dữ liệu mẫu sinh động: 3 Roles, 5 Profiles & Accounts (gồm admin, staff, customer `kh1` có dị ứng và pass `'123'`, customer `kh2`, và tài khoản bị khóa `kh_locked`), 6 Bàn ăn (đầy đủ các trạng thái), 4 Danh mục, 12 Món ăn (đầy đủ tag thời tiết và dị ứng), giỏ hàng mẫu, đơn hàng mẫu, thanh toán và đặt bàn.

