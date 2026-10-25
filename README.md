# 🍽️ Hệ Thống Quản Lý Nhà Hàng & Đặt Món Trực Tuyến (Pure RESTful API)

## Database dùng chung với nhóm (2026-10-25)

- Ứng dụng dùng **MySQL 8.4**, database riêng `restaurant_moc`; SQLite in-memory chỉ là mặc định của bộ test nhanh (`phpunit.xml`).
- Máy hiện tại chạy MySQL tại `127.0.0.1:3307` qua `E:\Nam4\mysql-khoa\start-mysql.ps1`. Máy khác điền cổng MySQL thực tế (thường 3306); không chép mật khẩu hay `.env` qua Git.
- Bản chuyển từ SQLite giữ dữ liệu nghiệp vụ và ID, đối chiếu dữ liệu trước khi chạy hai migration còn thiếu trên DB MySQL. SQLite cũ và bản sao lưu trong `.codex/mysql-switch-20261025/` được giữ để dự phòng; `.codex/` không được commit/chia sẻ vì có cấu hình và dữ liệu riêng tư.
- DB của Khoa `restaurant_laravel_khoa` không bị thay đổi. Cache và phiên đăng nhập web tạm thời không chuyển; tài khoản, lịch sử, token API và dữ liệu nghiệp vụ được giữ.
- Bổ sung migration **120500** trước POS **121000** để tránh trùng tên khóa ngoại MySQL khi đổi tên bảng cũ. Không sửa nội dung migration 121000 đã triển khai. Trên schema POS đã nâng cấp, 120500 không thay đổi gì.
- Cài mới trên **database trống**: `php artisan migrate --seed`. Cập nhật database có dữ liệu: sao lưu trước, chạy `php artisan migrate`, **không chạy lại seeder** và không dùng `migrate:fresh`.
- Khi đổi kết nối: dừng worker/scheduler, chạy `php artisan config:clear`, rồi khởi động lại API/worker sau khi kiểm tra DB đích. Không chạy kiểm thử phá dữ liệu vào `restaurant_moc` hay DB của Khoa; MySQL test phải là database riêng.

## Email, thông báo và Mộc đồng hành (2026-10-23)

- Khách: chuông `/notifications`, khung **Hỏi Mộc** trên website (đăng nhập để dùng chat).
- Nhân viên: `/staff/support`, tiếp nhận hội thoại trước khi trả lời. Một người phụ trách; AI dừng ngay khi chuyển người thật.
- Quản trị: `/admin/communications`, kết quả SMTP, lỗi và gửi lại có xác nhận nguy cơ trùng + nhật ký; xem góp ý sau bữa ăn.
- Chat dùng polling 2,5–3 giây khi mở; chỉ có thông báo trong web không thể đánh thức khách đã đóng web. Email là kênh bên ngoài; chưa tích hợp SMS, Zalo hay Web Push.

Chạy thêm hai terminal tại backend (ngoài API và frontend):

```powershell
php artisan queue:work database --queue=communications --sleep=1 --timeout=45 --tries=1
php artisan schedule:work
```

`schedule:work` chạy cả các tác vụ vận hành đã có; production dùng cron `schedule:run` mỗi phút và quản lý worker bằng process supervisor. Khởi động lại worker sau khi sửa code/config.

Email thật cần `MAIL_MAILER=smtp`, SMTP hợp lệ và `COMMUNICATIONS_EMAIL_ENABLED=true`. Gmail dùng App Password, không dùng mật khẩu đăng nhập. Không commit `.env` hay gửi khóa Gemini/mật khẩu lên chat.

Ở máy demo, đặt `COMMUNICATIONS_TEST_RECIPIENT` bằng Gmail kiểm thử của bạn: thư vẫn gửi thật nhưng **mọi thư chuyển về hộp thư kiểm thử**, không gửi tới khách mẫu. Khi triển khai thật, bỏ biến này; production luôn dùng người nhận thực. Biến này chỉ bảo vệ gửi mail, không ẩn nội dung nghiệp vụ khỏi người đang đăng nhập đúng tài khoản.

`dining:communications:dispatch` mỗi phút tạo nhắc lịch trước 60 phút, lời mời đánh giá sau 2 giờ (tối đa một lời mời mỗi bữa và một/ngày/tài khoản; bữa tại bàn tính theo phiên POS) và thông báo chat chưa đọc có giới hạn. Trước khi gửi, kiểm tra lại hủy/đổi lịch/đã nhận bàn, tài khoản khóa, email đã đổi và thông báo bị thay thế. Email có **hạn gửi tự động** (2 giờ cập nhật, 24 giờ hủy/không đến/chat, 48 giờ mời đánh giá, 7 ngày biên nhận, nhắc lịch tới giờ hẹn; cấu hình `EMAIL_TTL_*`): quá hạn → `EMAIL_EXPIRED`, retry không gia hạn, thông báo trong app vẫn giữ. Chỉ phản hồi SMTP 4xx mới tự gửi lại; timeout/kết quả không xác định/5xx chờ quản trị kiểm tra. `ACCEPTED` chỉ là máy chủ đã nhận, không phải khách đã đọc. SMTP không có bảo đảm gửi đúng một lần tuyệt đối khi tiến trình chết sau khi máy chủ đã nhận thư. Mỗi việc chỉ được đưa lại vào hàng đợi sau 10 phút và scheduler ngừng đẩy thêm khi worker dừng.

Gemini dùng `GEMINI_API_KEY` chỉ ở backend; mặc định model `gemini-3.5-flash-lite`, 40 lượt/tài khoản/ngày và 500 lượt/toàn app/ngày. Giới hạn này là **số lượt gọi Gemini** (tra cứu đơn/lượt đặt và chuyển nhân viên xử lý nội bộ không tính), không phải hạn mức tiền cứng của Google. Giới hạn thao tác: khách 12/phút, nhân viên 60/phút, gửi lại email 10/phút (`SUPPORT_*_PER_MINUTE`, `EMAIL_RETRY_PER_MINUTE`). Chỉ gửi thực đơn và chính sách công khai cùng nội dung chat được cảnh báo trước cho khách; tra cứu đơn/lượt đặt được xử lý nội bộ, không đưa kết quả riêng tư vào lịch sử gửi sang Gemini. AI chỉ tư vấn, không tự đặt/hủy/thu/hoàn tiền. Câu trả lời vẫn có thể sai; giá và món trong thẻ lấy lại từ DB, giao dịch cuối cùng luôn qua màn nghiệp vụ.

Nhân viên phụ trách có thể **trả hội thoại về hàng chờ**; ADMIN **chuyển người phụ trách** hoặc **tiếp quản** — đều cần lý do và được ghi nhật ký. Khóa tài khoản nhân viên đưa các hội thoại họ đang phụ trách về hàng chờ. Biên nhận giải thích tiền món, phí giao, giảm trừ/bồi hoàn và tổng; hoàn tiền sau lúc thu hiện tách riêng, biên nhận lịch sử không bị sửa.

Schema communications gồm `2026_10_23_165959`, `2026_10_23_191711` và `2026_10_23_233204` (hạn gửi, claim hàng đợi, khóa giới hạn đánh giá). **Ngày 25/10, DB MySQL `restaurant_moc` đã chạy đủ cả ba và migration sửa sổ tiền `2026_10_23_090000` sau khi chuyển/đối chiếu dữ liệu**. SQLite dự phòng vẫn giữ trạng thái cũ, không áp migration vào đó. Máy thành viên có dữ liệu cần sao lưu và xem `migrate:status` trước khi cập nhật; riêng migration sổ tiền phải được đối chiếu số liệu. Worker cần khởi động lại sau khi cập nhật. Khi schema communications chưa đủ, dispatcher tạm dừng (`communications.migration_pending`). Con trỏ đọc dùng ID tin nhắn, không dùng timestamp theo giây. Không dùng `migrate:fresh` trên DB có dữ liệu.

Kiểm tra: `php artisan test --compact --filter='GuestCommunicationTest|SupportChatTest'`. Kịch bản trình duyệt nằm ở frontend `scripts/communications-e2e.cjs`, chỉ chạy với DB dùng một lần, email tắt và worker riêng. Bộ kiểm thử PHP dùng SQLite in-memory, không gọi SMTP hay Gemini thật.

> **Backend Service:** Laravel 13 pure RESTful API phục vụ các nền tảng Frontend (React, Next.js, Mobile App, v.v.).
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

- **Framework:** Laravel 13.x
- **Ngôn ngữ:** PHP 8.5 trong môi trường hiện tại (xem ràng buộc chính xác trong `composer.json`)
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
- **PHP** theo ràng buộc `composer.json`/`composer.lock` (máy hiện tại dùng PHP 8.5).
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
DB_DATABASE=restaurant_moc
DB_USERNAME=restaurant_moc
DB_PASSWORD=
```
*(Tạo database `restaurant_moc` và tài khoản có quyền trên database này; không dùng database của người khác. Máy hiện tại dùng port 3307, ví dụ trên dùng cổng MySQL thông thường 3306)*.

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
php artisan migrate --seed
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

Hệ thống đã chuẩn bị sẵn các tài khoản với mọi vai trò. **Mật khẩu mặc định cho tất cả các tài khoản là `123`**:

| STT | Username | Password | Vai trò | Email | Trạng thái | Mục đích kiểm thử |
|:---:|:---|:---:|:---|:---|:---:|:---|
| 1 | `admin` | `123` | **Quản trị viên** (Admin) | `admin@restaurant.com` | `ACTIVE` | Test API Quản trị (`/api/v1/admin/*`) |
| 2 | `staff1` | `123` | **Nhân viên** (Staff) | `staff1@restaurant.com` | `ACTIVE` | Test API Bếp/Thu ngân/Đặt bàn |
| 3 | `kh1` | `123` | **Khách hàng** (Customer) | `khachhang1@gmail.com` | `ACTIVE` | Test Hồ sơ, Giỏ hàng, Đặt món |
| 4 | `kh2` | `123` | **Khách hàng** (Customer) | `khachhang2@gmail.com` | `ACTIVE` | Test Khách hàng 2 |
| 5 | `kh_locked`| `123` | **Khách hàng** (Customer) | `locked@gmail.com` | `LOCKED` | Test chặn đăng nhập tài khoản bị khóa (403) |

> [!NOTE]
> Khi đăng nhập lần đầu với mật khẩu `123`, hệ thống sẽ tự động băm mật khẩu sang chuẩn **BCrypt** an toàn và lưu vào CSDL mà không làm gián đoạn người dùng.

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
