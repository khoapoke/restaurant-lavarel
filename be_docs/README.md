# 📖 TÀI LIỆU HỆ THỐNG BACKEND (BACKEND SYSTEM DOCUMENTATION)
## Nhà Hàng & Đặt Món Trực Tuyến – Laravel 11 RESTful Architecture

> **Mục tiêu:** Cung cấp tài liệu nghiệp vụ, đặc tả kỹ thuật và chuẩn giao tiếp API hoàn chỉnh cho toàn bộ hệ thống.  
> **Kiến trúc:** Bounded Context Modular Monolith (`app/Modules/*`) theo chuẩn Pure RESTful Backend Service.

---

## 🗂️ 1. Bản Đồ Tài Liệu Theo Module Nghiệp Vụ (Module Specifications)

Mỗi module sở hữu một tài liệu đặc tả độc lập chuẩn Software Architect, tích hợp đầy đủ **Tổng quan, Thành phần kỹ thuật, Domain Rules và chi tiết từng Use Case / API**:

| STT | Module | Tệp đặc tả chi tiết | Số lượng API | Các tính năng & quy tắc kỹ thuật trọng tâm |
| :---: | :--- | :--- | :---: | :--- |
| **1** | **`Auth`** | [auth.md](modules/auth.md) | 11 APIs | Đăng ký, Đăng nhập, Hồ sơ cá nhân, Quản trị tài khoản, Auto-upgrade BCrypt, Rule `AUTH-LOCK`. |
| **2** | **`Menu`** | [menu.md](modules/menu.md) | 14 APIs | Danh mục & Món ăn, Redis Caching (`menu:categories:active`), Meilisearch Typo-Tolerance, Gợi ý món ăn theo thời tiết thực tế, Xóa mềm. |
| **3** | **`Cart`** | [cart.md](modules/cart.md) | 5 APIs | Giỏ hàng 1-1 với tài khoản, Bảng pivot `MON_AN_GIO_HANG` không có ID tự tăng, kỹ thuật `attach`/`updateExistingPivot`. |
| **4** | **`Order`** | [order.md](modules/order.md) | 7 APIs | Checkout giỏ hàng, Redis Distributed Lock 10s, Snapshot đơn giá, FSM 5 trạng thái đơn hàng, Đồng bộ trạng thái thanh toán. |
| **5** | **`Reservation`** | [reservation.md](modules/reservation.md) | 11 APIs | Tìm bàn trống, Đặt bàn trước, Redis Lock 5s, Kiểm tra xung đột khung giờ $\pm 119$ phút, Đồng bộ trạng thái bàn ăn. |
| **6** | **`Payment`** | [payment.md](modules/payment.md) | 7 APIs | Quản lý thanh toán 1-1 đơn hàng, Sinh mã VietQR động chuẩn Napas 247, Webhook đối soát tự động, Xác nhận tiền mặt. |
| **7** | **`Report`** | [report.md](modules/report.md) | 5 APIs | Dashboard tổng quan 4 nhóm chỉ số, Doanh thu thực thu, Thống kê đơn hàng, Món ăn bán chạy, Tỷ lệ lấp đầy bàn ăn. |
| **8** | **`Recommendation`** | [recommendation.md](modules/recommendation.md) | 3 APIs | Đồ thị tri thức Neo4j Bolt, Gợi ý món bán kèm (Market Basket Analysis), Gợi ý cá nhân hóa (Collaborative Filtering), Lọc dị ứng. |

*Tổng cộng toàn hệ thống:* **63 Use Cases / API Endpoints** được đặc tả chi tiết.

---

## 📐 2. Các Tài Liệu Kiến Trúc & Giao Tiếp Bổ Trợ

| Tài liệu | Đường dẫn | Nội dung tóm tắt |
| :--- | :--- | :--- |
| **Quy Ước Hệ Thống Cốt Lõi** | [system_conventions.md](system_conventions.md) | Bảng tra cứu nhanh quy tắc bất biến: Enums, Redis keys, Soft Delete, Pivot rules. |
| **Bản đồ Module & Tiến độ** | [modules.md](modules.md) | Bảng theo dõi tiến độ hoàn thành các module và danh mục API. |
| **Chuẩn Giao Tiếp API** | [api_protocol_specification.md](api_protocol_specification.md) | Quy ước Response Envelopes (`ApiResponse`), Mã lỗi HTTP chuẩn, Headers và mẫu Axios Interceptor. |
| **Từ Điển Thuật Ngữ & Eloquent Mapping** | [glossary_and_laravel_eloquent_mapping.md](glossary_and_laravel_eloquent_mapping.md) | Ánh xạ 12 Models Eloquent với cơ sở dữ liệu `QL_NHAHANG_CNJAVA_V2`, Backed Enums và quan hệ quan trọng. |
| **Quyết Định Kiến Trúc (ADR)** | [memory/architectural-decisions.md](memory/architectural-decisions.md) | Lưu trữ các quyết định kỹ thuật lớn (ADR-001 đến ADR-024) của dự án. |
| **Bài Học & Lỗi Môi Trường** | [memory/lessons-learned.md](memory/lessons-learned.md) | Tổng kết các sự cố, nguyên nhân và giải pháp kỹ thuật đã ghi nhận. |

---

## 🏛️ 3. Quy Ước Cấu Trúc Cho Mỗi Tệp Đặc Tả Module (`be_docs/modules/*.md`)

Mỗi tệp tài liệu module được xây dựng theo chuẩn cấu trúc 4 phần thống nhất:

1. **Tổng quan & Trách nhiệm:** Phạm vi nghiệp vụ của module, danh sách phân hệ khách hàng vs. quản trị.
2. **Thành phần kỹ thuật:** Danh sách Models, Services, Controllers, Form Requests, API Resources và Routes với đường dẫn file thực tế trong `app/Modules/<Module>/`.
3. **Domain Rules:** Toàn bộ các quy tắc bất biến của module (Redis Lock, Cache TTL, Soft Delete, State Transition matrix, Transaction isolation).
4. **Use Cases Chi Tiết (Mỗi API = 1 Use Case):**
   - **Mã Use Case & Tên tính năng:** (Ví dụ `ORD-01: Checkout Đặt hàng`)
   - **Actor & Phân quyền:** Vai trò được phép gọi (`CUSTOMER`, `ADMIN`, `STAFF`).
   - **Precondition:** Điều kiện tiên quyết trước khi request được xử lý.
   - **Validation Rules:** Bảng mô tả quy tắc kiểm thực dữ liệu (FormRequest).
   - **Luồng thực thi / Thuật toán (Execution Flow & Algorithms):** Các bước thực thi tuần tự từ Service đến Database (hoặc giải thuật tính toán chuyên sâu).
   - **Máy trạng thái / Biểu đồ luồng:** Biểu đồ Mermaid minh họa trực quan.
   - **Request / Response Envelopes mẫu:** JSON chuẩn Frontend có thể dùng mock data.
   - **Alternative Flow & Edge Cases:** Danh sách các mã lỗi HTTP tương ứng (400, 401, 403, 404, 409, 422, 429).

