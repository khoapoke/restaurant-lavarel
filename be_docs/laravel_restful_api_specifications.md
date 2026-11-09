# 📑 TỔNG HỢP ĐẶC TẢ LARAVEL RESTFUL API (MASTER API SPECIFICATIONS INDEX)

> **Lưu ý kiến trúc:** Để phục vụ việc refactor hệ thống sang mô hình **Modular Monolith**, toàn bộ tài liệu đặc tả API chi tiết 901 dòng trước đây đã được phân rã thành **7 file tài liệu module chuyên biệt** trong thư mục [`be_docs/api/`](api/).  
> File này đóng vai trò là **Trang Mục Lục Tổng Quan (Master Navigation Index)** giúp điều hướng nhanh đến tài liệu của từng module.

---

## 🧭 1. Bản Đồ Tài Liệu API Theo Từng Module

| STT | Module | File Đặc Tả API Chi Tiết | Số API | Phạm Vi Nghiệp Vụ Cốt Lõi |
| :---: | :--- | :--- | :---: | :--- |
| **0** | **Quy chuẩn API** | [**`be_docs/api/README.md`**](api/README.md) | - | **Chuẩn Response Envelopes, Mã lỗi HTTP, Headers & Code mẫu Axios** |
| **1** | **`Auth`** | [**`be_docs/api/auth.md`**](api/auth.md) | 11 APIs | Đăng ký, Đăng nhập, Đăng xuất, Hồ sơ cá nhân, Quản lý tài khoản Admin |
| **2** | **`Menu`** | [**`be_docs/api/menu.md`**](api/menu.md) | 14 APIs | Danh mục món, Danh sách món ăn phân trang, Lọc & Gợi ý thời tiết, Quản trị thực đơn |
| **3** | **`Cart`** | [**`be_docs/api/cart.md`**](api/cart.md) | 5 APIs | Xem giỏ hàng, Thêm/Sửa/Xóa món ăn trong giỏ, Làm rỗng giỏ |
| **4** | **`Order`** | [**`be_docs/api/order.md`**](api/order.md) | 7 APIs | Checkout tạo đơn (Redis Lock), Hủy đơn, Lịch sử đơn hàng, FSM chuyển trạng thái bếp |
| **5** | **`Reservation`** | [**`be_docs/api/reservation.md`**](api/reservation.md) | 11 APIs | Tra cứu bàn trống, Đặt bàn (Redis Lock), Sửa/Hủy bàn, Quản lý bàn ăn Admin |
| **6** | **`Payment`** | [**`be_docs/api/payment.md`**](api/payment.md) | 6 APIs | Sinh mã VietQR, Đối soát giao dịch, Hoàn tiền, Webhook biến động số dư |
| **7** | **`Report`** | [**`be_docs/api/report.md`**](api/report.md) | 5 APIs | Dashboard tổng quan, Báo cáo Doanh thu, Đơn hàng, Món bán chạy, Lấp đầy bàn |

---

## 📌 2. Quy Ước Kiến Trúc Nòng Cốt (Architecture Conventions)

1. **Định tuyến toàn hệ thống:** Toàn bộ route có tiền tố `/api/v1/`.
2. **Headers bắt buộc từ Client:**
   - `Accept: application/json`
   - `Content-Type: application/json`
   - `Authorization: Bearer <sanctum_token>`
3. **Chuẩn hóa Response Envelope:**
   - Thành công: `{"success": true, "code": 200, "message": "...", "data": { ... }}`
   - Thất bại: `{"success": false, "code": 4xx/5xx, "message": "...", "errors": { ... }}`
4. **Cơ chế Bảo vệ & Hiệu năng Cao:**
   - **Redis In-Memory Caching:** Lưu trữ danh mục thực đơn và gợi ý món ăn theo thời tiết.
   - **Redis Distributed Locks:** Chống Race Condition khi đặt bàn (`BKG-02`) và double-click checkout (`ORD-01`).
   - **Global Exception Shielding:** Triệt tiêu hoàn toàn mã lỗi HTML và che chắn file stack trace an toàn.

---

*Vui lòng bấm vào từng liên kết bảng trên để xem chi tiết đầy đủ Request Body, Response JSON Samples và Ghi chú nghiệp vụ của từng Module.*
