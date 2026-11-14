# 🧭 BẢN ĐỒ TIẾN ĐỘ & RADAR KIẾN TRÚC HỆ THỐNG (MODULE STATUS TRACKER)

> **Mục đích:** Theo dõi tiến độ triển khai API và vòng đời các Module nghiệp vụ trong hệ thống theo quy ước ký hiệu.  
> **Đặc tả chi tiết:** Toàn bộ Use Cases, Domain Rules, luồng thực thi và thuật toán chuyên sâu được lưu trữ tại [be_docs/modules/](modules/).
> 
> **Quy ước Ký hiệu Trạng thái:**
> - `X`: Đã hoàn thành (Completed / Done)
> - `O`: Đang làm / Đang triển khai (In Progress)
> - ` ` *(để trống)*: Chưa làm (Pending / Backlog)

---

## 📊 1. Bảng Tổng Kết Trạng Thái Toàn Hệ Thống (Status Metrics Summary)

| STT | Nhóm Hạng Mục Theo Dõi | Đã Hoàn Thành (`X`) | Đang Làm (`O`) | Chưa Làm *(để trống)* | Tổng Cộng | Tỷ Lệ Hoàn Thành (%) |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: |
| **1** | **API Logic & Nghiệp vụ (Endpoints)** | **63** | **0** | **0** | **63** | **100%** |
| **2** | **Module Nghiệp vụ (Modules)** | **8** | **0** | **0** | **8** | **100%** |
| **3** | **Lộ trình Refactor Kiến Trúc Module** | **8** | **0** | **0** | **8** | **100%** |

---

## 📋 2. Bảng Tổng Hợp Tiến Độ Các Module (Executive Summary)

| STT | Tên Module | Phạm vi nghiệp vụ | Số lượng API | Chi tiết đặc tả | Trạng thái Logic | Trạng thái Folder | Ghi chú kỹ thuật nổi bật |
| :---: | :--- | :--- | :---: | :---: | :---: | :---: | :--- |
| **1** | **`Auth`** | Tài khoản, Hồ sơ, Phân quyền | 11 APIs | [auth.md](modules/auth.md) | `X` | `X` | Auto-Upgrade BCrypt, Sanctum Token, RBAC |
| **2** | **`Menu`** | Danh mục, Món ăn, Gợi ý | 14 APIs | [menu.md](modules/menu.md) | `X` | `X` | In-Memory Redis Cache, Meilisearch Typo-Tolerance, OpenWeatherMap API |
| **3** | **`Cart`** | Giỏ hàng khách hàng | 5 APIs | [cart.md](modules/cart.md) | `X` | `X` | Bảng liên kết Pivot nhiều-nhiều |
| **4** | **`Order`** | Đặt hàng, Xử lý trạng thái FSM | 7 APIs | [order.md](modules/order.md) | `X` | `X` | Redis Distributed Lock, Snapshot đơn giá |
| **5** | **`Reservation`** | Quản lý bàn & Đặt bàn | 11 APIs | [reservation.md](modules/reservation.md) | `X` | `X` | Redis Distributed Lock, Kiểm tra trùng giờ $\pm 119'$ |
| **6** | **`Payment`** | Thanh toán, VietQR, Webhook | 7 APIs | [payment.md](modules/payment.md) | `X` | `X` | VietQR Napas 247, Webhook đối soát |
| **7** | **`Report`** | Báo cáo & Dashboard Admin | 5 APIs | [report.md](modules/report.md) | `X` | `X` | Tổng hợp Aggregates SQL hiệu năng cao |
| **8** | **`Recommendation`** | Gợi ý đồ thị Neo4j | 3 APIs | [recommendation.md](modules/recommendation.md) | `X` | `X` | Market Basket Analysis & Graph Filtering |
| **9** | **`Core` / `Shared`** | Enums, Middlewares, Exceptions | Hạ tầng | (Xem mục 4 bên dưới) | `X` | `X` | Unified JSON Envelope, Exception Shielding |

*Tổng số API Endpoint đã hoàn thành và kiểm thử:* **63 APIs**

---

## 🔍 3. Checklist Tiến Độ Từng Module Nghiệp Vụ

### 🛡️ MODULE 1: AUTH (Xác Thực, Tài Khoản & Phân Quyền) – [`be_docs/modules/auth.md`](modules/auth.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `USR-01` | `POST` | `/api/v1/auth/register` | Đăng ký tài khoản khách hàng mới | `X` |
| `USR-02` | `POST` | `/api/v1/auth/login` | Đăng nhập hệ thống & Auto-upgrade BCrypt | `X` |
| `USR-03` | `POST` | `/api/v1/auth/logout` | Đăng xuất & Thu hồi Sanctum Token | `X` |
| `USR-04` | `GET` | `/api/v1/customer/profile` | Lấy thông tin hồ sơ cá nhân & dị ứng | `X` |
| `USR-05` | `PUT` | `/api/v1/customer/profile` | Cập nhật thông tin cá nhân & dị ứng | `X` |
| `USR-06` | `PUT` | `/api/v1/customer/password` | Đổi mật khẩu tài khoản | `X` |
| `ADM-01` | `GET` | `/api/v1/admin/accounts` | Danh sách tài khoản (phân trang, lọc role/status) | `X` |
| `ADM-02` | `GET` | `/api/v1/admin/accounts/{id}` | Chi tiết tài khoản & thông tin nhân viên/khách | `X` |
| `ADM-03` | `POST` | `/api/v1/admin/accounts/staff` | Tạo mới tài khoản Nhân viên phục vụ | `X` |
| `ADM-04` | `PUT` | `/api/v1/admin/accounts/{id}` | Cập nhật thông tin tài khoản | `X` |
| `ADM-05` | `PATCH`| `/api/v1/admin/accounts/{id}/status` | Khóa / Kích hoạt tài khoản & Thu hồi token | `X` |

---

### 🥗 MODULE 2: MENU (Thực Đơn, Danh Mục & Món Ăn) – [`be_docs/modules/menu.md`](modules/menu.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `MNU-01` | `GET` | `/api/v1/customer/categories` | Danh sách danh mục đang bán (Redis Cache) | `X` |
| `MNU-02` | `GET` | `/api/v1/customer/dishes` | Danh sách món ăn phân trang | `X` |
| `MNU-03` | `GET` | `/api/v1/customer/dishes/search` | Tìm kiếm & lọc món (giá, dị ứng, danh mục) | `X` |
| `MNU-04` | `GET` | `/api/v1/customer/dishes/recommendations/weather` | Gợi ý món theo thời tiết thực tế (Redis Cache) | `X` |
| `MNU-07` | `GET` | `/api/v1/customer/dishes/{id}` | Chi tiết món ăn | `X` |
| `MNU-08` | `GET` | `/api/v1/admin/dishes` | Danh sách toàn bộ món ăn cho Admin | `X` |
| `MNU-09` | `POST` | `/api/v1/admin/categories` | Thêm mới danh mục món ăn | `X` |
| `MNU-10` | `PUT` | `/api/v1/admin/categories/{id}` | Cập nhật thông tin danh mục | `X` |
| `MNU-11` | `PATCH`| `/api/v1/admin/categories/{id}/visibility`| Ẩn / Hiện danh mục thực đơn | `X` |
| `MNU-12` | `DELETE`| `/api/v1/admin/categories/{id}` | Xóa mềm danh mục (tự ẩn các món thuộc danh mục) | `X` |
| `MNU-13` | `POST` | `/api/v1/admin/dishes` | Thêm mới món ăn | `X` |
| `MNU-14` | `PUT` | `/api/v1/admin/dishes/{id}` | Cập nhật món ăn (giá, ảnh, mô tả, dị ứng) | `X` |
| `MNU-15` | `PATCH`| `/api/v1/admin/dishes/{id}/availability` | Ẩn / Hiện / Đánh dấu còn hoặc hết món | `X` |
| `MNU-16` | `DELETE`| `/api/v1/admin/dishes/{id}` | Xóa mềm món ăn (giữ toàn vẹn đơn hàng cũ) | `X` |

---

### 🛒 MODULE 3: CART (Giỏ Hàng Mua Sắm) – [`be_docs/modules/cart.md`](modules/cart.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `CRT-01` | `GET` | `/api/v1/customer/cart` | Lấy chi tiết giỏ hàng & tổng tiền tạm tính | `X` |
| `CRT-02` | `POST` | `/api/v1/customer/cart/items` | Thêm món vào giỏ hàng | `X` |
| `CRT-03` | `PUT` | `/api/v1/customer/cart/items/{dishId}` | Cập nhật số lượng món ăn trong giỏ | `X` |
| `CRT-04` | `DELETE`| `/api/v1/customer/cart/items/{dishId}`| Xóa một món ra khỏi giỏ hàng | `X` |
| `CRT-05` | `DELETE`| `/api/v1/customer/cart/clear` | Làm rỗng giỏ hàng | `X` |

---

### 📦 MODULE 4: ORDER (Đơn Hàng & Vận Hành Bếp) – [`be_docs/modules/order.md`](modules/order.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `ORD-01` | `POST` | `/api/v1/customer/orders/checkout` | Đặt hàng từ giỏ (Khóa Redis chống Race Condition) | `X` |
| `ORD-02` | `PUT` | `/api/v1/customer/orders/{id}/cancel` | Khách tự hủy đơn hàng (khi đơn chưa xác nhận) | `X` |
| `ORD-04` | `GET` | `/api/v1/customer/orders` | Lịch sử đơn hàng của khách (phân trang, lọc status)| `X` |
| `ORD-05` | `GET` | `/api/v1/customer/orders/{id}` | Chi tiết đơn hàng của khách hàng | `X` |
| `ORD-06` | `GET` | `/api/v1/admin/orders` | Danh sách đơn hàng toàn hệ thống | `X` |
| `ORD-07` | `GET` | `/api/v1/admin/orders/{id}` | Chi tiết đơn hàng cho Quản trị viên/Bếp | `X` |
| `ORD-08` | `PATCH`| `/api/v1/admin/orders/{id}/status` | Cập nhật trạng thái quy trình đơn hàng | `X` |

---

### 🪑 MODULE 5: RESERVATION (Bàn Ăn & Đặt Bàn Trước) – [`be_docs/modules/reservation.md`](modules/reservation.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `BKG-01` | `GET` | `/api/v1/customer/reservations/available-tables` | Tìm bàn trống theo ngày, giờ và số người | `X` |
| `BKG-02` | `POST` | `/api/v1/customer/reservations` | Đặt bàn mới (Khóa Redis chống trùng bàn) | `X` |
| `BKG-03` | `PUT` | `/api/v1/customer/reservations/{id}` | Cập nhật lịch hẹn đặt bàn (trước $\ge 60$ phút) | `X` |
| `BKG-04` | `PUT` | `/api/v1/customer/reservations/{id}/cancel` | Hủy lượt đặt bàn | `X` |
| `BKG-05` | `GET` | `/api/v1/customer/reservations` | Lịch sử đặt bàn của khách hàng | `X` |
| `BKG-06` | `GET` | `/api/v1/customer/reservations/{id}` | Chi tiết lượt đặt bàn | `X` |
| `BKG-07` | `GET` | `/api/v1/admin/tables` | Danh sách toàn bộ bàn ăn kèm trạng thái | `X` |
| `BKG-08` | `PATCH`| `/api/v1/admin/tables/{id}/status` | Cập nhật trạng thái bàn ăn (Trống/Sử dụng/Bảo trì)| `X` |
| `BKG-09` | `GET` | `/api/v1/admin/reservations` | Danh sách lượt đặt bàn hệ thống (lọc ngày/status)| `X` |
| `BKG-10` | `GET` | `/api/v1/admin/reservations/{id}` | Chi tiết lượt đặt bàn cho Admin/Lễ tân | `X` |
| `BKG-11` | `PATCH`| `/api/v1/admin/reservations/{id}/status`| Duyệt / Tiếp nhận / Hoàn tất đặt bàn & Sync bàn | `X` |

---

### 💳 MODULE 6: PAYMENT (Thanh Toán & Đối Soát Giao Dịch) – [`be_docs/modules/payment.md`](modules/payment.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `PAY-01` | `GET` | `/api/v1/admin/payments` | Danh sách lịch sử giao dịch thanh toán | `X` |
| `PAY-02` | `GET` | `/api/v1/admin/payments/{id}` | Chi tiết giao dịch thanh toán | `X` |
| `PAY-03` | `PATCH`| `/api/v1/admin/payments/{id}/confirm` | Xác nhận đã thu tiền mặt tại quầy | `X` |
| `PAY-04` | `GET` | `/api/v1/customer/payments/{orderId}` | Lấy thông tin thanh toán & mã VietQR động | `X` |
| `PAY-05` | `POST` | `/api/v1/admin/payments/{id}/refund` | Hoàn tiền giao dịch & Tự hủy đơn hàng | `X` |
| `PAY-06` | `GET` | `/api/v1/customer/payments/{orderId}/status`| Kiểm tra trạng thái thanh toán đơn hàng | `X` |
| `PAY-07` | `POST` | `/api/v1/customer/payments/webhook` | Webhook nhận biến động số dư VietQR | `X` |

---

### 📈 MODULE 7: REPORT (Báo Cáo Thống Kê & Phân Tích) – [`be_docs/modules/report.md`](modules/report.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `RPT-00` | `GET` | `/api/v1/admin/reports/dashboard` | Dashboard tổng hợp toàn bộ 4 nhóm chỉ số | `X` |
| `RPT-01` | `GET` | `/api/v1/admin/reports/revenue` | Báo cáo doanh thu thực thu theo chuỗi ngày | `X` |
| `RPT-02` | `GET` | `/api/v1/admin/reports/orders` | Báo cáo thống kê đơn hàng & tỷ lệ thành công | `X` |
| `RPT-03` | `GET` | `/api/v1/admin/reports/top-dishes` | Báo cáo danh sách món ăn bán chạy nhất | `X` |
| `RPT-04` | `GET` | `/api/v1/admin/reports/reservations` | Báo cáo số lượt đặt bàn & tỷ lệ lấp đầy bàn | `X` |

---

### 🕸️ MODULE 8: RECOMMENDATION (Động Cơ Gợi Ý Đồ Thị Tri Thức Neo4j) – [`be_docs/modules/recommendation.md`](modules/recommendation.md)

| Mã API | Method | Endpoint URL | Tên tính năng | Trạng thái |
| :---: | :---: | :--- | :--- | :---: |
| `GRA-01` | `GET` | `/api/v1/customer/dishes/{dishId}/recommendations/graph` | Gợi ý món bán kèm (Cross-sell / Market Basket Analysis) | `X` |
| `GRA-02` | `GET` | `/api/v1/customer/recommendations/personalized` | Gợi ý cá nhân hóa theo khẩu vị & lịch sử đặt hàng (Collaborative Filtering) | `X` |
| `GRA-03` | `GET` | `/api/v1/customer/recommendations/graph-stats` | Thống kê số lượng Nodes & Relationships trên đồ thị Neo4j | `X` |

---

## 🧩 4. MODULE 9: CORE / SHARED (Nền Tảng Dùng Chung Toàn Hệ Thống)

- **Vị trí thư mục:** `app/Modules/Core/`
- **Trách nhiệm:** Chứa toàn bộ các thành phần hạ tầng chung mà các module khác phụ thuộc vào:
  - **Base Controller:** `BaseController` (kế thừa `Controller`, tích hợp `ApiResponse`).
  - **Trait:** `ApiResponse` (chuẩn hóa Envelope phản hồi JSON: `success`, `code`, `message`, `data`/`errors`).
  - **7 Backed Enums:** `RoleType`, `AccountStatus`, `OrderStatus`, `TableStatus`, `ReservationStatus`, `PaymentMethod`, `PaymentStatus`.
  - **Middlewares:** `ForceJsonResponse`, `CheckAccountActive`, `CheckRole`.
  - **Exceptions:** Global Exception Shielding trong `bootstrap/app.php`.
  - **Database Migrations & Seeders:** 12 bảng cốt lõi và `RestaurantSeeder` tại `database/`.
- **Trạng thái Logic:** `X`
- **Trạng thái Refactor Folder:** `X`
