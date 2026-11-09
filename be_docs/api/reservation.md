# 🪑 ĐẶC TẢ API MODULE: RESERVATION (BÀN ĂN & ĐẶT BÀN TRƯỚC)

> **Namespace dự kiến:** `App\Modules\Reservation\`  
> **Controllers:** `Customer\ReservationController`, `Admin\TableController`, `Admin\ReservationController`  
> **Models:** `DiningTable` (`BAN_AN`), `Reservation` (`DAT_BAN`)  
> **Redis Lock:** `lock:reservation:table:{tableId}` (5s)

---

## 1. CUSTOMER RESERVATION APIS

### 1.1. Tìm kiếm bàn trống theo ngày, giờ & số khách (`BKG-01`)
- **Method & URL:** `GET /api/v1/customer/tables/available?date=2026-06-15&time=19:00&guestCount=4`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Available tables retrieved successfully",
  "data": [
    {
      "tableId": 1,
      "tableName": "Bàn số 1",
      "capacity": 4,
      "location": "Khu vực sảnh chính",
      "status": "AVAILABLE"
    },
    {
      "tableId": 2,
      "tableName": "Bàn số 2",
      "capacity": 6,
      "location": "Khu vực ngoài trời",
      "status": "AVAILABLE"
    }
  ]
}
```
- **Luật kiểm tra trùng giờ:** Một bàn được xem là trống nếu không có lượt đặt bàn nào khác ở trạng thái `PENDING` hoặc `CONFIRMED` trong khoảng thời gian $\pm 119$ phút so với giờ hẹn.

---

### 1.2. Tạo lượt đặt bàn mới (`BKG-02`)
- **Method & URL:** `POST /api/v1/customer/reservations`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Redis Lock:** `lock:reservation:table:{tableId}` (5s, block 3s chống đặt trùng)
- **Request Body:**
```json
{
  "tableId": 1,
  "reservationTime": "2026-06-15 19:00:00",
  "guestCount": 4,
  "note": "Cần chuẩn bị ghế trẻ em"
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Reservation created successfully",
  "data": {
    "reservationId": 4,
    "reservationTime": "2026-06-15T19:00:00",
    "guestCount": 4,
    "status": "PENDING",
    "note": "Cần chuẩn bị ghế trẻ em",
    "table": {
      "tableId": 1,
      "tableName": "Bàn số 1",
      "capacity": 4,
      "location": "Khu vực sảnh chính"
    }
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Table is already reserved for the selected time slot",
  "errors": null
}
```

---

### 1.3. Sửa & Hủy lượt đặt bàn (`BKG-03`, `BKG-04`)
- **Sửa lịch hẹn:** `PUT /api/v1/customer/reservations/{reservationId}`
- **Hủy đặt bàn:** `POST /api/v1/customer/reservations/{reservationId}/cancel`
- **Ràng buộc thời gian:** Khách chỉ được sửa hoặc hủy lịch đặt bàn trước giờ hẹn tối thiểu 60 phút (`now()->diffInMinutes($reservation->thoi_gian, false) >= 60`). Nếu dưới 60 phút, hệ thống từ chối với lỗi:
```json
{
  "success": false,
  "code": 409,
  "message": "Cannot modify or cancel reservation less than 60 minutes before scheduled time",
  "errors": null
}
```

---

## 2. ADMIN TABLE & RESERVATION MANAGEMENT APIS

### 2.1. Quản lý danh mục bàn ăn (`BKG-07`, `BKG-08`)
- **Danh sách bàn:** `GET /api/v1/admin/tables`
- **Cập nhật trạng thái bàn:** `PATCH /api/v1/admin/tables/{tableId}/status`
- **Request Body:**
```json
{
  "status": "MAINTENANCE"
}
```
- **Các trạng thái bàn:** `AVAILABLE` (Trống), `RESERVED` (Đã đặt trước), `OCCUPIED` (Đang sử dụng), `MAINTENANCE` (Bảo trì).

---

### 2.2. Duyệt & Điều phối lượt đặt bàn (`BKG-11`)
- **Method & URL:** `PATCH /api/v1/admin/reservations/{reservationId}/status`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Request Body:**
```json
{
  "targetStatus": "CONFIRMED",
  "adminNote": "Đã giữ Bàn số 2 cạnh cửa sổ"
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Reservation confirmed successfully",
  "data": {
    "reservationId": 3,
    "status": "CONFIRMED",
    "tableId": 2,
    "tableStatus": "RESERVED"
  }
}
```
- **Ghi chú tự động đồng bộ bàn:**
  - Khi Admin duyệt sang `CONFIRMED`: Trạng thái bàn tự động chuyển sang `RESERVED`.
  - Khi hoàn tất `COMPLETED` hoặc hủy `CANCELLED` / `REJECTED`: Trạng thái bàn tự động giải phóng về `AVAILABLE`.

