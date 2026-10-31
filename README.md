# 🍽️ Hệ Thống Quản Lý Nhà Hàng & Đặt Món Trực Tuyến (Pure RESTful API)

> **Backend Service:** Laravel 11 pure RESTful API phục vụ các nền tảng Frontend (React, Next.js, Mobile App, v.v.).  
> **Kiến trúc:** Service - Controller - FormRequest - API Resource với chuẩn hóa JSON Envelope và Global Exception Shielding.

---

## 📌 1. Giới thiệu Tổng quan (Overview)

Dự án này là phân hệ **Backend Pure RESTful API** thuộc Hệ thống Quản lý Nhà hàng & Đặt món ăn. Toàn bộ logic nghiệp vụ được thiết kế tuân thủ theo mô hình L3 chuyên sâu, tách biệt hoàn toàn tầng giao diện (không render Blade views/Web routes).

### Đặc điểm nổi bật:
- **Chuẩn hóa phản hồi (Unified JSON Envelope):** Mọi response API đều được đóng gói theo khuôn mẫu:
  - Thành công: `{"success": true, "code": 200, "message": "...", "data": {...}}`
  - Thất bại: `{"success": false, "code": 4xx/5xx, "message": "...", "errors": {...}}`
- **Xác thực & Phân quyền (Authentication & RBAC):** 
  - Sử dụng **Laravel Sanctum** cấp Bearer Token có thời hạn và tự động thu hồi khi đăng xuất hoặc bị Admin khóa tài khoản.
  - Phân quyền theo 3 vai trò chính: `ADMIN` (Quản trị viên), `STAFF` (Nhân viên), `CUSTOMER` (Khách hàng).
- **Lá chắn ngoại lệ toàn cục (Global Exception Shielding):**
  - Tự động ép header `Accept: application/json` thông qua middleware `ForceJsonResponse`.
  - Triệt tiêu hành vi redirect về web login của Laravel khi không truyền token (trả về HTTP 401 JSON sạch).
  - Bắt toàn bộ lỗi (Validation 422, Unauthenticated 401, Forbidden 403, ModelNotFound 404, Server Error 500) mà không làm lộ file path hay stack trace nhạy cảm.
- **Tự động nâng cấp mật khẩu (Auto-Upgrade BCrypt):**
  - Cho phép tài khoản khởi tạo bằng mật khẩu mẫu (như `'123'`) tự động chuyển đổi thành băm BCrypt an toàn ngay trong lần đăng nhập đầu tiên.

---

## 🛠️ 2. Công nghệ Sử dụng (Tech Stack)

- **Framework:** Laravel 11.x
- **Ngôn ngữ:** PHP 8.2+ / PHP 8.3 (hỗ trợ String-Backed Enums, Constructor Promotion, Attributes)
- **Hệ quản trị CSDL:** MySQL 8.0+ / 8.4+ 
- **Xác thực:** Laravel Sanctum (Token-based Authentication)
- **ORM:** Eloquent ORM (tắt `$timestamps = false`, cast mảng JSON `allergens`, `dietaryPreferences`, `savedAddresses`)

---

## 📂 3. Cấu trúc Thư mục Nòng cốt

```text
restaurant-lavarel/
├── app/
│   ├── Enums/                     # Backed Enums (RoleType, AccountStatus, OrderStatus...)
│   ├── Http/
│   │   ├── Controllers/Api/       # Controllers chuyên biệt thuần API
│   │   │   ├── AuthController.php # USR-01, USR-02, USR-03 (Login/Register/Logout)
│   │   │   ├── Admin/             # Phân hệ Quản trị viên (Account, Dish, Order...)
│   │   │   └── Customer/          # Phân hệ Khách hàng (Profile, Cart, Order...)
│   │   ├── Middleware/            # ForceJsonResponse, CheckAccountActive, CheckRole
│   │   ├── Requests/              # FormRequests xác thực dữ liệu đầu vào
│   │   └── Resources/             # API Resources chuẩn hóa dữ liệu trả về
│   ├── Models/                    # 12 Eloquent Models ánh xạ 12 bảng CSDL
│   ├── Services/                  # Tầng xử lý nghiệp vụ (Business Logic L3, Transactions)
│   └── Traits/
│       └── ApiResponse.php        # Trait đóng gói JSON Envelope
├── database/
│   ├── migrations/                # Schema 12 bảng + Sanctum personal_access_tokens
│   └── seeders/
│       └── RestaurantSeeder.php   # Dữ liệu giả định đầy đủ (Tài khoản, Món ăn, Bàn...)
├── docs/                          # TÀI LIỆU ĐẶC TẢ KỸ THUẬT & MEMORY HỆ THỐNG
│   ├── laravel_restful_api_specifications.md
│   ├── business_logic_and_validation_rules.md
│   ├── restaurant_management_system_database_specification.md
│   └── memory/                    # Nhật ký kiến trúc (ADR) & bài học kinh nghiệm
└── routes/
    └── api.php                    # Toàn bộ định tuyến API (prefix: /api/v1/)
```

---

## 🚀 4. Hướng dẫn Cài đặt & Khởi chạy cho Team (Setup Guide)

### Bước 1: Yêu cầu môi trường
Đảm bảo máy của thành viên đã cài đặt:
- **PHP** >= 8.2 (Khuyến nghị dùng PHP 8.3 trong Laragon hoặc XAMPP).
- **Composer** (bản 2.x).
- **MySQL** (chạy trên port 3306).

> [!TIP]
> Nếu bạn dùng **Laragon trên Windows**, bạn có thể thêm đường dẫn PHP và Composer vào biến môi trường PATH hoặc chạy trực tiếp từ Terminal của Laragon:
> ```powershell
> $env:PATH = "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;C:\laragon\bin\composer;$env:PATH"
> ```

---

### Bước 2: Clone repository & Cài đặt dependencies
Mở Terminal và clone source code:
```bash
git clone <URL_REPO_CUA_NHOM>
cd restaurant-lavarel
```

Cài đặt các gói thư viện qua Composer:
```bash
composer install
```

---

### Bước 3: Cấu hình file môi trường `.env`
Sao chép file `.env.example` thành `.env`:
```bash
cp .env.example .env
```
*(Trên Windows PowerShell nếu không có lệnh `cp`, bạn dùng `copy .env.example .env`)*.

Mở file `.env` và kiểm tra cấu hình kết nối database:
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=restaurant_laravel
DB_USERNAME=root
DB_PASSWORD=
```
*(Tạo database có tên `restaurant_laravel` trên phpMyAdmin/HeidiSQL nếu chưa có)*.

---

### Bước 4: Tạo Application Key
Tạo key mã hóa cho ứng dụng:
```bash
php artisan key:generate
```

---

### Bước 5: Chạy Migration & Seeder dữ liệu mẫu
Khởi tạo cấu trúc 12 bảng và nạp sẵn dữ liệu kiểm thử (Tài khoản, Danh mục, Món ăn, Bàn ăn, Đơn hàng, Đặt bàn):
```bash
php artisan migrate:fresh --seed
```

---

### Bước 6: Khởi chạy Development Server
Khởi động API server nội bộ:
```bash
php artisan serve
```
Mặc định ứng dụng sẽ chạy tại địa chỉ: `http://127.0.0.1:8000`

---

## 👥 5. Danh Sách Tài Khoản Mẫu Để Kiểm Thử (Seed Accounts)

Hệ thống đã chuẩn bị sẵn các tài khoản với mọi vai trò phục vụ kiểm thử:

| STT | Username | Password | Vai trò | Email | Trạng thái | Mục đích kiểm thử |
|:---:|:---|:---:|:---|:---|:---:|:---|
| 1 | `admin` | `Password@123` | **Quản trị viên** (Admin) | `admin@restaurant.com` | `ACTIVE` | Test API Quản trị (`/api/v1/admin/*`) |
| 2 | `staff1` | `Password@123` | **Nhân viên** (Staff) | `staff1@restaurant.com` | `ACTIVE` | Test API Bếp/Thu ngân/Đặt bàn |
| 3 | `kh1` | `123` | **Khách hàng** (Customer) | `khachhang1@gmail.com` | `ACTIVE` | Test Hồ sơ, Giỏ hàng, Đặt món (tự nâng cấp BCrypt) |
| 4 | `kh2` | `Password@123` | **Khách hàng** (Customer) | `khachhang2@gmail.com` | `ACTIVE` | Test Khách hàng 2 |
| 5 | `kh_locked`| `Password@123` | **Khách hàng** (Customer) | `locked@gmail.com` | `LOCKED` | Test chặn đăng nhập tài khoản bị khóa (403) |

> [!NOTE]
> - Tài khoản `admin`, `staff1`, `kh2`, `kh_locked` mặc định dùng mật khẩu băm BCrypt chuẩn: **`Password@123`**.
> - Riêng tài khoản `kh1` dùng mật khẩu mẫu đơn giản là **`123`** để kiểm thử tính năng tự động phát hiện và băm sang **BCrypt** an toàn ngay trong lần đăng nhập đầu tiên.

---

## 📑 6. Danh Mục Tài Liệu Tham Khảo Trong Dự Án

Mọi thành viên trong team khi phát triển chức năng mới **bắt buộc** đọc và tuân thủ các tài liệu đặc tả đặt trong thư mục `docs/`:

1. **[Tài liệu Đặc tả RESTful API](docs/laravel_restful_api_specifications.md):**
   - Danh sách chi tiết các API (Method, URL, Header, Request Body, Response JSON Envelope thành công/lỗi).
2. **[Quy tắc Nghiệp vụ & Ràng buộc Dữ liệu](docs/business_logic_and_validation_rules.md):**
   - Các thuật toán trọng tâm L3 (Giỏ hàng không dùng cột ID tự tăng, chuyển trạng thái đơn hàng FSM, tính tiền & khuyến mãi, quy tắc khóa tài khoản `AUTH-LOCK`...).
3. **[Đặc tả Cơ sở Dữ liệu](docs/restaurant_management_system_database_specification.md):**
   - Chi tiết 12 bảng, cấu trúc khóa chính phức hợp, quan hệ Foreign Key, kiểu dữ liệu JSON mở rộng.
4. **[Nhật ký Quyết định Kiến trúc (ADR)](docs/memory/architectural-decisions.md):**
   - Lưu trữ các quyết định kỹ thuật từ `ADR-001` đến `ADR-008` (Cấu trúc API, Middleware, Error Handling, Logic Khách hàng, Quản lý Admin...).
5. **[Bài học Kinh nghiệm & Khắc phục Lỗi](docs/memory/lessons-learned.md):**
   - Các lưu ý tránh lỗi khi code Laravel Eloquent trên bảng không có timestamps, xử lý token invalidation, v.v.

---

## ⚠️ 7. Quy Tắc Bắt Buộc Dành Cho Thành Viên Khi Code (Team Guidelines)

1. **Pure RESTful API:** Tuyệt đối không can thiệp hay viết logic vào `resources/views`, `resources/css`, `resources/js`, `vite.config.js` hay `routes/web.php`.
2. **Tuân thủ Chuẩn Envelope:** Toàn bộ Controller API phải sử dụng `ApiResponse` Trait (`$this->successResponse(...)`, `$this->errorResponse(...)`).
3. **Xóa mềm (Soft Delete):** Tuyệt đối **không gọi lệnh `$dish->delete()` vật lý** trên bảng món ăn `MON_AN` vì sẽ vi phạm ràng buộc khóa ngoại với chi tiết đơn hàng cũ. Luôn cập nhật cờ xóa mềm (`isDeleted = true`, `isVisible = false`).
4. **Bảo mật:** Model `Account` luôn bật thuộc tính `protected $hidden = ['mat_khau'];` để tránh rò rỉ mật khẩu trong JSON.
5. **Quy tắc Khóa tài khoản (Rule AUTH-LOCK):** Admin không được tự khóa chính mình. Khi khóa một tài khoản, phải gọi `$account->tokens()->delete()` để thu hồi toàn bộ token phiên làm việc tức thì.
