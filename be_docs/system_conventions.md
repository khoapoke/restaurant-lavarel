# ⚙️ QUY ƯỚC HỆ THỐNG CỐT LÕI (BACKEND SYSTEM CONVENTIONS)
## Bảng Tra Cứu Quy Tắc Bất Biến & Chuẩn Mực Kỹ Thuật (System Cheat Sheet)

> **Mục tiêu:** Cung cấp tài liệu tra cứu nhanh (Quick Reference) toàn bộ các quy tắc, chuẩn mực và ràng buộc kỹ thuật cốt lõi trong hệ thống Laravel 11.  
> **Dành cho:** Kỹ sư phần mềm, Frontend Developer và AI Agents khi triển khai hoặc kiểm thử tính năng mới.

---

## 📨 1. Chuẩn Hóa Phản Hồi JSON (Unified ApiResponse Envelope)

Mọi Controller API kế thừa `BaseController` và sử dụng Trait `ApiResponse`:

```json
// Thành công đơn / cập nhật: 200 OK
{ "success": true, "code": 200, "message": "...", "data": { ... } }

// Tạo mới tài nguyên: 201 Created
{ "success": true, "code": 201, "message": "...", "data": { ... } }

// Phân trang (Pagination): 200 OK
{
  "success": true,
  "code": 200,
  "message": "...",
  "data": {
    "items": [ ... ],
    "pagination": {
      "currentPage": 1,
      "perPage": 12,
      "totalItems": 35,
      "lastPage": 3
    }
  }
}

// Báo lỗi: 4xx / 5xx
{ "success": false, "code": 422, "message": "The given data was invalid.", "errors": { "field": ["Detail error"] } }
```

> [!IMPORTANT]
> **Quy tắc Vàng API:** 100% không bao giờ trả về HTML hoặc redirect về login. Middleware `ForceJsonResponse` tự động ép header `Accept: application/json`.

---

## 🏷️ 2. Danh Mục 9 Backed Enums Hệ Thống (`App\Modules\Core\Enums`)

| Enum Class | Giá trị Enum trên API (FE nhận & gửi) | Chuỗi lưu trữ trong MySQL (Database Value) | Nhãn hiển thị tiếng Việt trên UI |
| :--- | :--- | :--- | :--- |
| **`RoleType`** | `ADMIN`, `STAFF`, `CUSTOMER` | `'ADMIN'`, `'STAFF'`, `'CUSTOMER'` | Quản trị viên, Nhân viên, Khách hàng |
| **`AccountStatus`**| `ACTIVE`, `LOCKED` | `'ACTIVE'`, `'LOCKED'` | Đang hoạt động, Đã bị khóa |
| **`OrderStatus`**  | `PENDING_CONFIRMATION`<br>`CONFIRMED`<br>`PREPARING`<br>`READY`<br>`DELIVERING`<br>`COMPLETED`<br>`CANCELLED` | `'Chờ xác nhận'`<br>`'Đã xác nhận'`<br>`'Đang chuẩn bị'`<br>`'Sẵn sàng'`<br>`'Đang giao'`<br>`'Đã giao'`<br>`'Đã hủy'` | Chờ xác nhận, Đã xác nhận, Đang chuẩn bị, Sẵn sàng, Đang giao, Đã hoàn tất, Đã hủy |
| **`TableStatus`**  | `AVAILABLE`, `RESERVED`, `OCCUPIED`, `MAINTENANCE` | `'Trống'`, `'Đã đặt'`, `'Đang phục vụ'`, `'Bảo trì'` | Bàn trống, Đã đặt, Đang phục vụ, Bảo trì |
| **`ReservationStatus`**| `PENDING_DEPOSIT`<br>`PENDING`<br>`CONFIRMED`<br>`OCCUPIED`<br>`REJECTED`<br>`CANCELLED`<br>`COMPLETED` | `'Chờ đặt cọc'`<br>`'Chờ xử lý'`<br>`'Đã xác nhận'`<br>`'Đang có khách'`<br>`'Từ chối'`<br>`'Đã hủy'`<br>`'Hoàn thành'` | Chờ đặt cọc (15 phút), Chờ xử lý, Đã xác nhận, Đang có khách, Bị từ chối, Đã hủy, Hoàn thành |
| **`PaymentMethod`**| `CASH`, `BANK_TRANSFER_QR` | `'Tiền mặt'`, `'Chuyển khoản'` | Tiền mặt, Chuyển khoản VietQR |
| **`PaymentStatus`**| `UNPAID`, `PAID`, `FAILED`, `REFUNDED` | `'Chưa thanh toán'`, `'Đã thanh toán'`, `'Thất bại'`, `'Đã hoàn tiền'` | Chưa thanh toán, Đã thanh toán, Thanh toán thất bại, Đã hoàn tiền |
| **`ChatSessionStatus`**| `BOT`, `WAITING_STAFF`, `STAFF_CONNECTED`, `CLOSED` | `'BOT'`, `'WAITING_STAFF'`, `'STAFF_CONNECTED'`, `'CLOSED'` | Chat với AI, Chờ nhân viên, Nhân viên đang hỗ trợ, Đã kết thúc |
| **`ChatSenderType`**| `CUSTOMER`, `STAFF`, `BOT` | `'CUSTOMER'`, `'STAFF'`, `'BOT'` | Khách hàng, Nhân viên, Chatbot |

---

## 🔒 3. Khóa Phân Tán & Bộ Nhớ Đệm Redis (Distributed Locks & Cache)

| Mục đích | Loại tài nguyên | Định dạng Key Pattern | TTL (Thời gian sống) | Cơ chế hủy / Giải phóng |
| :--- | :--- | :--- | :---: | :--- |
| **Checkout Đơn hàng** | Distributed Lock | `lock:order:checkout:{accountId}` | 10 giây | Tự động nhả trong `finally` block |
| **Đặt bàn ăn** | Distributed Lock | `lock:reservation:table:{tableId}` | 5 giây | Tự động nhả trong `finally` block |
| **Danh mục Menu** | In-Memory Cache | `menu:categories:active` | 3600s (1 giờ) | Cache Busting (`Cache::forget`) khi Admin sửa danh mục |
| **Gợi ý Thời tiết** | In-Memory Cache | `weather_rec_{lat}_{lon}` | 900s (15 phút)| Hết hạn tự nhiên theo TTL |

---

## 🗑️ 4. Quy Tắc Xóa Mềm & Ràng Buộc Khóa Ngoại (Soft Delete Policy)

- **Món ăn (`MON_AN`) & Danh mục (`DANH_MUC`):**
  - **TUYỆT ĐỐI KHÔNG** gọi lệnh xóa vật lý `$dish->delete()`. Vi phạm ràng buộc khóa ngoại trong `CHI_TIET_DON_HANG` và `MON_AN_GIO_HANG`.
  - **Luật Xóa mềm:** Luôn cập nhật cờ `$dish->update(['da_xoa' => true, 'hien_thi' => false])`.
  - **Truy vấn menu khách:** Luôn gán scope `.where('da_xoa', false)->where('hien_thi', true)`.

---

## 🛒 5. Quy Tắc Bảng Pivot Không Có Cột ID Tự Tăng (BelongsToMany)

- **Bảng `MON_AN_GIO_HANG` & `CHI_TIET_DON_HANG`:**
  - Khóa chính là phức hợp `(ma_gio_hang, ma_mon_an)` hoặc `(ma_don_hang, ma_mon_an)`.
  - **TUYỆT ĐỐI KHÔNG** gọi `$item->save()` trên Model Pivot vì Eloquent sẽ sinh `WHERE id = NULL`.
  - **Kỹ thuật chuẩn:** Sử dụng các phương thức Pivot qua quan hệ `belongsToMany`:
    - Thêm món: `$cart->dishes()->attach($dishId, ['so_luong' => $qty])`
    - Cập nhật số lượng: `$cart->dishes()->updateExistingPivot($dishId, ['so_luong' => $qty])`
    - Xóa món: `$cart->dishes()->detach($dishId)`
    - Xóa rỗng giỏ: `$cart->dishes()->detach()`

---

## 🛡️ 6. Quy Tắc Bảo Mật Tài Khoản (Rule AUTH-LOCK & BCrypt)

1. **Rule AUTH-LOCK:** Khi Admin khóa tài khoản:
   - Chặn Admin không được tự khóa chính mình: `$target->ma_tai_khoan !== $admin->ma_tai_khoan`.
   - Cập nhật `trang_thai = AccountStatus::LOCKED`.
   - Lập tức gọi `$target->tokens()->delete()` để thu hồi toàn bộ Sanctum tokens, ép logout ngay lập tức.
2. **Auto-Upgrade BCrypt:** Khi khách đăng nhập lần đầu với mật khẩu rõ `'123'` từ file SQL mẫu:
   - Hệ thống so khớp `hash_equals($stored, $plain)`.
   - Nếu khớp, tự động mã hóa lại bằng `Hash::make($plain)` và lưu lại DB.
3. **Ẩn mật khẩu:** Model `Account` luôn bật `protected $hidden = ['mat_khau'];`.

---

## 🏗️ 7. Hạ Tầng Dịch Vụ Đa CSDL & Cổng Kết Nối (Polyglot Persistence Ports)

Hệ thống kết hợp 4 công nghệ cơ sở dữ liệu chuyên biệt phục vụ các bài toán khác nhau:

| Dịch vụ / Công nghệ | Môi trường / Nền tảng | Cổng Host (Port) | Vai trò & Trách nhiệm kiến trúc |
| :--- | :--- | :---: | :--- |
| **MySQL 8.0** | RDBMS Chính (Laragon) | `3306` | Lưu trữ dữ liệu quan hệ cốt lõi, đảm bảo toàn vẹn ACID transactions (Đơn hàng, Bàn ăn, Thanh toán). |
| **Redis 7.2** | In-Memory Cache (Docker) | `6379` | Cache danh mục thực đơn (TTL 3600s), Khóa phân tán Distributed Locks chống Race Condition (`lock:order`, `lock:reservation`). |
| **Neo4j 5 Community** | Graph Database (Docker) | `7474` (HTTP)<br>`7687` (Bolt) | Đồ thị tri thức khách hàng - món ăn, phục vụ gợi ý món bán kèm (Market Basket Analysis) và cá nhân hóa (Collaborative Filtering). |
| **Meilisearch 1.7** | Search Engine (Docker) | `7700` | Động cơ tìm kiếm full-text đa ngôn ngữ, chịu lỗi chính tả (Typo-Tolerance) và tìm tiếng Việt không dấu siêu tốc. |
| **Laravel 11 API** | Backend Service (PHP 8.3) | `8000` | Cung cấp RESTful API cho toàn bộ Client ứng dụng. |

---

## 🔍 8. Động Cơ Tìm Kiếm Thông Minh Meilisearch (Laravel Scout)

- **Driver:** Laravel Scout (`SCOUT_DRIVER=meilisearch`).
- **Endpoint Service:** `http://127.0.0.1:7700`, API Master Key: `masterKey123`.
- **Tên Index:** `dishes` (ánh xạ từ Model `App\Modules\Menu\Models\Dish`).
- **Cấu hình Typo-Tolerance tiếng Việt:**
  - `minWordSizeForTypos: { oneTypo: 3, twoTypos: 7 }`: Cho phép sai 1 ký tự với các từ tiếng Việt 3–6 chữ cái (`pho`, `com`, `tra`, `che`...).
  - `searchableAttributes`: `['name', 'description', 'category_name']`.
  - `filterableAttributes`: `['category_id', 'is_available', 'is_visible', 'is_deleted']`.
  - `sortableAttributes`: `['price', 'id']`.
- **Graceful Fallback:** Toàn bộ truy vấn Meilisearch được bọc trong `try ... catch (\Throwable $e)`. Nếu container Meilisearch ngoại tuyến hoặc gặp sự cố, hệ thống tự động fallback về câu lệnh SQL `LIKE '%keyword%'` trên MySQL, bảo đảm 100% không làm gián đoạn trải nghiệm của khách.


