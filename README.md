# 🛠️ TÀI LIỆU KỸ THUẬT DỰ ÁN (TECHNICAL DEVELOPER MANUAL)
# Backend Service: Restaurant Management & Online Food Ordering

> **Dành riêng cho Kỹ sư phần mềm, Backend Developers và Người đánh giá kỹ thuật (Technical Reviewers)**  
> **Kiến trúc:** Pure RESTful API | Modular Monolith | Layered Architecture (Controller - FormRequest - Service L3 - Model - Resource)  
> **Framework & Runtime:** Laravel 11.x | PHP 8.3 | MySQL 8.0+ | Redis 7.2 (Docker) | Laravel Sanctum

---

## 📌 1. Tổng quan Kiến trúc Kỹ thuật (Architectural Overview)

Dự án này là phân hệ **Backend Service thuần RESTful API** phục vụ các Client bên ngoài (ReactJS, NextJS, VueJS hoặc Mobile Apps). Toàn bộ luồng dữ liệu được thiết kế theo hướng không phụ thuộc giao diện (zero Blade views / zero Web sessions).

### Các nguyên tắc thiết kế nòng cốt:
1. **Kiến trúc Modular Monolith (Module-based):**
   - Hệ thống được phân rã thành các Module độc lập theo ranh giới nghiệp vụ (Bounded Contexts): `Auth`, `Menu`, `Cart`, `Order`, `Reservation`, `Payment`, `Report`, và `Core/Shared`.
   - Mỗi module tự đóng gói các thành phần chuyên biệt: `Controllers`, `Services` (tầng nghiệp vụ L3 chuyên sâu), `Models` (Eloquent ORM), `Requests` (xác thực dữ liệu FormRequest), và `Resources` (chuẩn hóa JSON đầu ra).
2. **Chuẩn hóa phản hồi tuyệt đối (Unified JSON Response Envelopes):**
   - Thành công: `{"success": true, "code": 200/201, "message": "...", "data": { ... }}`
   - Thất bại: `{"success": false, "code": 4xx/5xx, "message": "...", "errors": { ... }}`
   - Xem chi tiết tại: [be_docs/api_protocol_specification.md](be_docs/api_protocol_specification.md).
3. **Lá chắn Ngoại lệ Toàn cục (Global Exception Shielding):**
   - Middleware `ForceJsonResponse` tự động ép header `Accept: application/json` cho mọi request đến `/api/*`, triệt tiêu triệt để hành vi mặc định redirect về route HTML `login` của Laravel khi thiếu Bearer Token.
   - Bắt và đóng gói toàn bộ các exception (`ValidationException` 422, `AuthenticationException` 401, `AuthorizationException` 403, `ModelNotFoundException` 404, `HttpException` 409/429, `Throwable` 500) mà không làm lộ file stack trace ra ngoài.
4. **Hiệu năng cao với Redis In-Memory Caching & Distributed Locks:**
   - Sử dụng thư viện thuần PHP `predis/predis` (v3.7) kết nối với Redis Server (Docker port 6379), không yêu cầu cài extension C `php_redis.dll`.
   - Caching menu danh mục (`menu:categories:active`) và gợi ý món theo thời tiết (`weather_rec_*`).
   - Sử dụng `Cache::lock()` chống tình trạng Race Condition (đặt trùng bàn ăn `BKG-02` hoặc double-click checkout `ORD-01`).

---

## 🧰 2. Tech Stack & Infrastructure

| Thành phần | Công nghệ / Thư viện | Vai trò kỹ thuật |
| :--- | :--- | :--- |
| **Backend Framework** | Laravel 11.x | Core Framework, Routing, Container DI, Artisan CLI |
| **Language & Runtime**| PHP 8.3 (ZTS/NTS) | Type hint nghiêm ngặt, Backed Enums, Constructor Promotion, Attributes |
| **Primary Database** | MySQL 8.0+ / 8.4+ (InnoDB) | Lưu trữ quan hệ 12 bảng chính, hỗ trợ cột JSON mở rộng (`chat_di_ung`, `so_thich_an_uong`, `the_thoi_tiet`) |
| **In-Memory Cache & Lock** | Redis 7.2 (Alpine - Docker) | Caching dữ liệu đọc tần suất cao và xử lý Khóa phân tán (Distributed Lock) |
| **Redis Client** | `predis/predis` ^3.7 | Driver PHP thuần kết nối Redis không cần cài đặt DLL |
| **Authentication** | Laravel Sanctum ^4.3 | Xác thực Stateless Bearer Token, quản lý abilities (`role:admin`, `role:customer`) |
| **External Integrations**| OpenWeatherMap API & VietQR Napas 247 | Gợi ý thực đơn theo thời tiết & sinh mã VietQR chuyển khoản ngân hàng |
| **Testing** | PHPUnit 11 / Laravel Test | Bộ test tự động kiểm thử nghiệp vụ, kết nối Redis và API contract |

---

## 📂 3. Cấu trúc Thư mục Hệ thống (Project Structure)

```text
restaurant-lavarel/
├── app/
│   ├── Http/
│   │   └── Controllers/Controller.php  # Base Controller gốc của Laravel
│   ├── Modules/                        # KIẾN TRÚC MODULAR MONOLITH (PURE MODULES)
│   │   ├── Core/                       # BaseController, ApiResponse, 7 Backed Enums, Middlewares
│   │   ├── Auth/                       # Account, UserProfile, Role, Controllers, Services, Requests, Resources
│   │   ├── Menu/                       # Category, Dish, Controllers, Services (Redis Cache), Requests, Resources
│   │   ├── Cart/                       # Cart, CartItem, Controllers, Services, Requests, Resources
│   │   ├── Order/                      # Order, OrderItem, Controllers, Services (Redis Lock), Requests, Resources
│   │   ├── Reservation/                # DiningTable, Reservation, Controllers, Services (Redis Lock)
│   │   ├── Payment/                    # Payment, VietQR, Controllers, Services, Requests, Resources
│   │   └── Report/                     # Dashboard & Analytics, Controllers, Services, Requests
│   └── Providers/
│       └── AppServiceProvider.php      # ServiceProvider cấu hình hệ thống & MorphMap
├── be_docs/                            # HỆ THỐNG TÀI LIỆU KỸ THUẬT (TECHNICAL DOCS)
│   ├── README.md                       # Trang mục lục điều hướng trung tâm
│   ├── api_protocol_specification.md   # Chuẩn Response Envelopes, Mã lỗi HTTP, Headers & Axios
│   ├── modules/                        # Đặc tả Use Cases & Thuật toán L3 chi tiết cho 8 module
│   │   ├── auth.md, menu.md, cart.md, order.md, reservation.md, payment.md, report.md, recommendation.md
│   ├── modules.md                      # Bản đồ Module & Bảng theo dõi tiến độ hoàn thành các Module
│   ├── glossary_and_laravel_eloquent_mapping.md # Ánh xạ 12 Model Eloquent & Foreign Key Matrix
│   └── memory/
│       ├── architectural-decisions.md  # Nhật ký quyết định kiến trúc hệ thống (ADR-001 -> ADR-024)
│       └── lessons-learned.md          # Lưu vết bài học kinh nghiệm và giải pháp fix lỗi môi trường
├── database/
│   ├── migrations/                     # Schema 12 bảng cơ sở dữ liệu + personal_access_tokens
│   └── seeders/
│       └── RestaurantSeeder.php        # Bộ dữ liệu mẫu đầy đủ cho toàn bộ hệ thống
├── OVERVIEW.md                         # Tài liệu mô tả chức năng & giá trị dự án cho Khách hàng/Người dùng
└── routes/
    └── api.php                         # Aggregator nạp toàn bộ Route từ app/Modules/*/Routes/api.php
```

> 🎯 **Kiến trúc Pure Modular:** Toàn bộ hệ thống hoạt động 100% thuần theo Module, không còn phụ thuộc vào các thư mục hay class tương thích ngược cũ. Xem chi tiết tại [TESTING_GUIDE.md](TESTING_GUIDE.md) để kiểm thử trực tiếp trên giao diện.

---

## 🚀 4. Hướng dẫn Cài đặt & Khởi chạy (Developer Setup Guide)

### Bước 1: Yêu cầu môi trường
- **PHP** >= 8.3 (Khuyến nghị dùng PHP 8.3 trong Laragon trên Windows).
- **Composer** bản 2.x.
- **MySQL** 8.0+ (Chạy trên cổng `3306`).
- **Docker Desktop** (Dùng để chạy container Redis `redis-server` trên cổng `6379`).

> [!TIP]
> **Cấu hình PATH trên Windows (PowerShell):**
> Nếu lệnh `php` hoặc `composer` chưa nhận diện được trong Terminal, hãy cấu hình biến môi trường PATH:
> ```powershell
> $env:PATH = "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;C:\laragon\bin\composer;$env:PATH"
> ```

---

### Bước 2: Clone repository & Cài đặt dependencies
```bash
git clone <URL_REPO>
cd restaurant-lavarel
composer install
```

---

### Bước 3: Cấu hình biến môi trường `.env`
Sao chép file `.env.example` thành `.env`:
```powershell
copy .env.example .env
```

Kiểm tra và cập nhật các thông số nòng cốt trong file `.env`:
```dotenv
APP_NAME=RestaurantAPI
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# 1. Cơ sở dữ liệu MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=restaurant_laravel
DB_USERNAME=root
DB_PASSWORD=

# 2. Redis Caching & Distributed Locks
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# 3. Cấu hình VietQR Napas 247
VIETQR_BANK_BIN=970403
VIETQR_ACCOUNT_NO=060269723389
VIETQR_TEMPLATE=compact2
```

Tạo application key:
```bash
php artisan key:generate
```

---

### Bước 4: Khởi động Redis Container qua Docker
Kiểm tra và khởi động container Redis đã có sẵn:
```bash
docker start <ten_container_redis_cua_ban>
```
*(Nếu chưa có container `redis-server`, chạy lệnh tạo mới sau:)*
```bash
docker run -d --name redis-server -p 6379:6379 --restart unless-stopped redis:7.2-alpine
```

Kiểm tra kết nối Redis:
```bash
docker exec redis-server redis-cli ping
# Kết quả phản hồi: PONG
```

---

### Bước 5: Chạy Migration & Seeder dữ liệu mẫu
Tạo sạch 12 bảng và nạp đầy đủ dữ liệu kiểm thử (Tài khoản, Danh mục, Món ăn, Bàn ăn, Đơn hàng, Lượt đặt bàn):
```bash
php artisan migrate:fresh --seed
```

---

### Bước 6: Khởi chạy API Development Server
```bash
php artisan serve
```
Server sẽ chạy tại địa chỉ: `http://127.0.0.1:8000` (Prefix API: `http://127.0.0.1:8000/api/v1/`).

---

## 🔑 5. Danh Sách Tài Khoản Kiểm Thử Mặc Định (Seed Credentials)

| STT | Username | Mật khẩu | Vai trò (`Role`) | Email | Trạng thái | Mục đích kiểm thử API |
| :---: | :--- | :---: | :--- | :--- | :---: | :--- |
| **1** | `admin` | `Password@123` | `ADMIN` | `admin@restaurant.com` | `ACTIVE` | Toàn quyền kiểm thử các API Quản trị (`/api/v1/admin/*`) |
| **2** | `admin1` | `123` | `ADMIN` | `admin@restaurant.com` | `ACTIVE` | Tài khoản admin phụ để test |
| **3** | `staff1` | `123` | `STAFF` | `staff1@restaurant.com` | `ACTIVE` | Kiểm thử nghiệp vụ Bếp, Phục vụ, Xếp bàn, Thu ngân |
| **4** | `kh1` | `123` | `CUSTOMER` | `khachhang1@gmail.com` | `ACTIVE` | Kiểm thử cơ chế **Auto-Upgrade BCrypt** trong lần login đầu tiên |
| **5** | `kh2` | `123` | `CUSTOMER` | `khachhang2@gmail.com` | `ACTIVE` | Kiểm thử Khách hàng đặt món, giỏ hàng, đặt bàn |
| **6** | `kh_locked` | `123` | `CUSTOMER` | `locked@gmail.com` | `LOCKED` | Kiểm thử middleware `account.active` chặn 403 Forbidden |
| **7** | `bian` | `123` | `CUSTOMER` | `anomyous41@gmail.com` | `ACTIVE` | tài khoản kiểm thử cho email |

---

## ⚡ 6. Cơ Chế Kỹ Thuật Chuyên Sâu (Deep Technical Implementations)

### 1. Redis Distributed Lock chống Race Condition
- **Đặt bàn ăn (`ReservationService::createReservation`):**  
  Bảo vệ tài nguyên bàn ăn bằng `Cache::lock("lock:reservation:table:{$tableId}", 5)`. Khóa tuần tự hóa trong 3 giây. Nếu có 2 request đặt cùng một bàn vào cùng khung giờ $\pm 119$ phút, request đến sau sẽ bị chặn lại hoặc nhận lỗi `409 Conflict`.
- **Tạo đơn hàng (`OrderService::checkout`):**  
  Bảo vệ phiên giỏ hàng bằng `Cache::lock("lock:order:checkout:{$accountId}", 10)`. Ngăn chặn hoàn toàn hiện tượng người dùng double-click nút thanh toán trên Web/App tạo ra 2 đơn hàng trùng lặp.

### 2. Tự động nâng cấp mật khẩu (Auto-Upgrade BCrypt)
- Khi gọi `POST /api/v1/auth/login`:
  - Nếu mật khẩu trong DB đã ở định dạng hash BCrypt (`$2y$`, `$2a$`, `$2b$`) $\rightarrow$ Dùng `Hash::check()`.
  - Nếu mật khẩu trong DB là chuỗi plaintext cũ (như `'123'`) $\rightarrow$ Sử dụng `hash_equals()` kiểm tra, nếu trùng khớp hệ thống lập tức băm `Hash::make()` và cập nhật đè xuống MySQL ngay tại thời điểm đăng nhập thành công.

### 3. Quy tắc toàn vẹn dữ liệu & Xóa mềm (Soft Delete Policy)
- Bảng `MON_AN` tuyệt đối **không được xóa vật lý** (`DELETE FROM MON_AN`) để tránh vi phạm khóa ngoại trong `CHI_TIET_DON_HANG`.
- Luôn sử dụng cờ `da_xoa = true` và `hien_thi = false`. Khi xóa mềm một danh mục, hệ thống tự động ẩn toàn bộ món ăn thuộc danh mục đó.

---

## 🧪 7. Kiểm Thử Tự Động (Automated Testing)

Chạy bộ test suite của toàn bộ hệ thống:
```bash
php artisan test
```

Kiểm tra cấu hình và kết nối Redis từ Laravel:
```bash
php artisan tinker --execute="echo Illuminate\Support\Facades\Redis::ping() ? 'Redis OK' : 'Redis Fail';"
```

---

## 📚 8. Bản Đồ Tài Liệu Kỹ Thuật Chi Tiết (Technical Documentation Map)

1. **[Mục Lục Tài Liệu Trung Tâm](be_docs/README.md):** Bản đồ điều hướng tài liệu toàn hệ thống.
2. **[Đặc tả Mã Lỗi & Response Envelopes](be_docs/api_protocol_specification.md):** Cấu trúc JSON chuẩn, HTTP status codes, mã lỗi nghiệp vụ, hướng dẫn header từ Client.
3. **[Đặc Tả Nghiệp Vụ Từng Module (Use Cases)](be_docs/modules/):** Thuật toán tính tiền, FSM trạng thái đơn hàng, FSM đặt bàn, xử lý giỏ hàng và 63 Use Cases chi tiết.
4. **[Bản Đồ Module & Tiến Độ Dự Án](be_docs/modules.md):** Danh mục các module, phân rã danh sách API, trạng thái đã làm xong / đang làm / chưa làm.
5. **[Đặc Tả Cơ Sở Dữ Liệu & Eloquent Mapping](be_docs/glossary_and_laravel_eloquent_mapping.md):** 12 Models Eloquent, bảng quan hệ nhiều - nhiều, Backed Enums, cách xử lý 6 bẫy lỗi mapping DB.
6. **[Nhật Ký Quyết Định Kiến Trúc (ADRs)](be_docs/memory/architectural-decisions.md):** Toàn bộ lịch sử quyết định kiến trúc từ ADR-001 đến ADR-024.
7. **[Bài Học Kinh Nghiệm (Lessons Learned)](be_docs/memory/lessons-learned.md):** Sổ tay xử lý sự cố môi trường và cạm bẫy kỹ thuật.
