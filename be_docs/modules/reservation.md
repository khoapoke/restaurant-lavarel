# 🪑 MODULE: RESERVATION – Business Logic & API Spec

> **Vị trí thư mục:** `app/Modules/Reservation/`  
> **Namespace:** `App\Modules\Reservation\`  
> **Phiên bản:** 2.0 (Streamlined Edition)

---

## 1. Tổng quan & Trách nhiệm

Module **Reservation** quản lý toàn bộ vòng đời đặt bàn trước của nhà hàng:
- Tìm bàn trống theo ngày, giờ và số người (loại trừ xung đột khung giờ ca ăn $\pm 119$ phút).
- Tạo phiếu đặt bàn kèm khóa phân tán Redis chống đặt trùng bàn.
- Quy tắc sửa/hủy lịch hẹn trước giờ đến tối thiểu 60 phút.
- Admin phê duyệt phiếu và tự động đồng bộ trạng thái bàn ăn (`BAN_AN.trang_thai`).

---

## 2. Thành phần kỹ thuật

| Thành phần | Lớp / Đường dẫn thực tế | Trách nhiệm |
| :--- | :--- | :--- |
| **Models** | `DiningTable` (`BAN_AN`)<br>`Reservation` (`DAT_BAN`) | Model bàn ăn và phiếu đặt bàn (quan hệ 1-N). |
| **Services** | `Customer\ReservationService`<br>`Admin\TableService`<br>`Admin\ReservationService` | Tìm bàn, tạo/sửa/hủy đặt bàn, kiểm tra xung đột thời gian và đồng bộ trạng thái bàn. |
| **Controllers**| `Customer\ReservationController`<br>`Admin\TableController`<br>`Admin\ReservationController` | Điều hướng các API đặt bàn và quản trị bàn ăn. |
| **Requests**  | `FindAvailableTablesRequest`, `CreateReservationRequest`<br>`UpdateReservationRequest`, `UpdateReservationStatusRequest` | Kiểm thực tham số tìm bàn và đặt bàn. |
| **Resources** | `TableResource`, `ReservationResource`<br>`AdminTableResource`, `AdminReservationResource` | Chuẩn hóa JSON phản hồi cho Frontend. |
| **Routes**    | `app/Modules/Reservation/Routes/api.php` | Định tuyến `/api/v1/customer/reservations/*` và `/api/v1/admin/*`. |

---

## 3. Domain Rules Bất Biến

### 3.1. Quy Tắc Xung Đột Khung Giờ $\pm 119$ Phút (Carbon Formula)
Mỗi ca dùng bữa tiêu chuẩn là 120 phút. Một bàn được coi là **bận (không thể đặt)** tại thời điểm $T_{\text{req}}$ nếu tồn tại phiếu đặt bàn thỏa mãn:
```php
$isBusy = Reservation::where('ma_ban', $tableId)
    ->whereIn('trang_thai', ['Chờ xử lý', 'Đã xác nhận'])
    ->whereBetween('thoi_gian', [
        $requestedTime->copy()->subMinutes(119),
        $requestedTime->copy()->addMinutes(119)
    ])->exists();
```

### 3.2. Quy Tắc Thời Gian Báo Trước (Advance Notice Policies)
- **Đặt bàn mới (`BKG-02`):** Thời gian đặt phải cách hiện tại ít nhất **30 phút**. Nếu trong `ghi_chu` có yêu cầu đặc biệt (*"sinh nhật", "vip", "phòng riêng", "trang trí"*), phải báo trước tối thiểu **120 phút (02 giờ)**.
- **Sửa / Hủy đặt bàn (`BKG-03`, `BKG-04`):** Khách chỉ được sửa hoặc hủy khi thời gian đến còn cách hiện tại **$\ge 60$ phút**. Nếu $< 60$ phút ném lỗi `409 Conflict`.

### 3.3. Khóa Phân Tán Redis Chống Đặt Trùng Bàn
- Key: `lock:reservation:table:{tableId}`, TTL 5 giây. Đảm bảo 2 khách đặt cùng 1 bàn tại cùng thời điểm sẽ không bị Race Condition.

### 3.4. Ma Trận Đồng Bộ Trạng Thái Bàn Ăn
| Hành động của Admin trên Phiếu Đặt Bàn (`BKG-11`) | Trạng thái `DAT_BAN` | Trạng thái `BAN_AN` tương ứng |
| :--- | :--- | :--- |
| Khách đặt cọc thành công / Admin xác nhận cọc | `CONFIRMED` (`Đã xác nhận`) | `RESERVED` (`Đã đặt`) |
| Admin duyệt đơn đặt trong ca hiện tại | `CONFIRMED` (`Đã xác nhận`) | `RESERVED` (`Đã đặt`) |
| Khách đến nhà hàng nhận bàn | `OCCUPIED` (`Đang có khách`) | `OCCUPIED` (`Đang phục vụ`) |
| Khách thanh toán ra về | `COMPLETED` (`Hoàn thành`) | `AVAILABLE` (`Trống`) |
| Đơn hết hạn cọc / Hủy phiếu | `CANCELLED` / `REJECTED` | `AVAILABLE` (`Trống`) |

### 3.5. Quy Tắc Đặt Cọc Bàn đơn giản (Conditional Peak-Hour Deposit Policy)
1. **Điều kiện yêu cầu đặt cọc:**
   - Số lượng khách `so_luong_khach >= 6` **VÀ**
   - Khung giờ đặt bàn rơi vào **Giờ cao điểm** (Ca trưa: `11:30 - 13:30`, Ca tối: `18:00 - 20:30`, mọi ngày trong tuần).
   - Nếu không thỏa mãn cả 2 điều kiện: Miễn phí đặt cọc (`tien_coc = 0`), trạng thái `PENDING`.
2. **Mức tiền cọc:**
   - Cố định **50.000 VNĐ / khách** ($\text{tiền cọc} = \text{so\_luong\_khach} \times 50.000$).
3. **Cơ chế giữ chỗ 15 phút (Hold Table Window):**
   - Phiếu cọc được gán trạng thái `PENDING_DEPOSIT` (`Chờ đặt cọc`) với `han_chot_coc = now() + 15 phút`.
   - Trong 15 phút, bàn được bảo vệ bởi công thức $\pm 119$ phút (không ai khác đặt được).
   - Nếu sau 15 phút khách chưa thanh toán, phiếu cọc hết hạn và bàn tự động mở lại cho người khác đặt.
4. **Thanh toán VietQR & Xác nhận:**
   - Hệ thống tự sinh mã VietQR nội dung `DB{id} COC` tương ứng số tiền cọc.
   - Hỗ trợ mock thanh toán nhanh cho FE qua API `BKG-13` (`mock-deposit`) hoặc nhân viên xác nhận tại quầy qua API `BKG-14` (`confirm-deposit`). Đồng thời webhook cổng thanh toán tự động xác nhận khi nhận được nội dung `DB{id}`.
5. **Quy chế hủy & Hoàn cọc:**
   - Khách được phép hủy trước giờ hẹn tối thiểu 60 phút và được hoàn lại 100% tiền cọc. Khi khách đến nhận bàn (`OCCUPIED`), nhân viên sẽ hoàn trả trực tiếp tiền cọc cho khách.

---

## 4. Schemas Dữ Liệu Chuẩn Dành Cho Frontend

### Schema 4.1: Bàn Ăn (`TableResource`)
```json
{
  "tableId": 3,
  "tableName": "Bàn VIP 01",
  "capacity": 6,
  "status": "AVAILABLE"
}
```

### Schema 4.2: Phiếu Đặt Bàn Khách Hàng (`ReservationResource`)
```json
{
  "reservationId": 25,
  "reservationTime": "2026-11-20 19:00:00",
  "guestCount": 6,
  "status": "PENDING_DEPOSIT",
  "note": "Kỷ niệm ngày cưới, cần bàn yên tĩnh",
  "depositAmount": 300000,
  "isDepositPaid": false,
  "depositDeadline": "2026-11-17T02:30:00+07:00",
  "qrCodeUrl": "https://img.vietqr.io/image/970436-1029384756-compact2.png?amount=300000&addInfo=DB25+COC",
  "table": { "tableId": 3, "tableName": "Bàn VIP 01", "capacity": 6 }
}
```

---

## 5. Bảng Tổng Hợp Endpoints Đặt Bàn

| Mã API | Method | Endpoint URL | Phân quyền | Body / Query Request | Response Payload | Mã HTTP |
| :---: | :---: | :--- | :---: | :--- | :--- | :---: |
| `BKG-01` | `GET` | `/api/v1/customer/reservations/available-tables` | Public | Query: `date`, `time`, `guestCount` | Danh sách `[TableResource]` | `200 / 422` |
| `BKG-02` | `POST` | `/api/v1/customer/reservations` | `CUSTOMER` | `{ "tableId", "reservationTime", "guestCount", "note"? }` | `201`: `ReservationResource` *(Chi tiết tại Mục 6)* | `201 / 400 / 409` |
| `BKG-03` | `PUT` | `/api/v1/customer/reservations/{id}` | `CUSTOMER` | `{ "tableId"?, "reservationTime"?, "guestCount"?, "note"? }` | `ReservationResource` đã sửa *(Quy tắc 60' tại Mục 6)* | `200 / 409` |
| `BKG-04` | `PUT` | `/api/v1/customer/reservations/{id}/cancel` | `CUSTOMER` | *(Không)* | `{ "message": "Reservation cancelled" }` *(Quy tắc 60' tại Mục 6)* | `200 / 409` |
| `BKG-05` | `GET` | `/api/v1/customer/reservations` | `CUSTOMER` | Query: `page`, `status` | Paginated `[ReservationResource]` | `200` |
| `BKG-06` | `GET` | `/api/v1/customer/reservations/{id}` | `CUSTOMER` | *(Không)* | `ReservationResource` (Schema 4.2) | `200 / 404` |
| `BKG-07` | `GET` | `/api/v1/admin/tables` | `ADMIN,STAFF` | Query: `status` | Danh sách `[AdminTableResource]` | `200` |
| `BKG-08` | `PATCH`| `/api/v1/admin/tables/{id}/status` | `ADMIN,STAFF` | `{ "status": "AVAILABLE|MAINTENANCE" }` | Cập nhật trạng thái bàn ăn | `200 / 404` |
| `BKG-09` | `GET` | `/api/v1/admin/reservations` | `ADMIN,STAFF` | Query: `page`, `date`, `status` | Paginated `[AdminReservationResource]` | `200` |
| `BKG-10` | `GET` | `/api/v1/admin/reservations/{id}` | `ADMIN,STAFF` | *(Không)* | `AdminReservationResource` | `200 / 404` |
| `BKG-11` | `PATCH`| `/api/v1/admin/reservations/{id}/status` | `ADMIN,STAFF` | `{ "targetStatus": string }` | Duyệt / Đồng bộ trạng thái bàn *(Chi tiết tại Mục 6)* | `200 / 400` |
| `BKG-12` | `GET` | `/api/v1/admin/reservations/time-slots` | `ADMIN,STAFF` | *(Không)* | Cấu hình khung giờ đặt bàn | `200` |
| `BKG-13` | `POST`| `/api/v1/customer/reservations/{id}/mock-deposit` | `CUSTOMER` | *(Không)* | `ReservationResource` (CONFIRMED) | `200 / 400 / 404` |
| `BKG-14` | `POST`| `/api/v1/admin/reservations/{id}/confirm-deposit` | `ADMIN,STAFF` | *(Không)* | `AdminReservationResource` (CONFIRMED) | `200 / 404` |
| `BKG-10` | `GET` | `/api/v1/admin/reservations/{id}` | `ADMIN` | *(Không)* | `AdminReservationResource` | `200 / 404` |
| `BKG-11` | `PATCH`| `/api/v1/admin/reservations/{id}/status` | `ADMIN` | `{ "targetStatus": string }` | Duyệt / Đồng bộ trạng thái bàn *(Chi tiết tại Mục 6)* | `200 / 400` |

---

## 6. Use Cases Nghiệp Vụ Phức Tạp (Complex Use Cases)

### UC BKG-02: Tạo Lượt Đặt Bàn Mới (Redis Lock 5s)
- **Actor:** Khách hàng (`CUSTOMER`).
- **Endpoint:** `POST /api/v1/customer/reservations`
- **Validation:**
  - `tableId`: `required|exists:BAN_AN,ma_ban`
  - `reservationTime`: `required|date_format:Y-m-d H:i:s|after:now`
  - `guestCount`: `required|integer|min:1`
  - `note`: `nullable|string|max:255`
- **Luồng thực thi (Bọc trong `Cache::lock("lock:reservation:table:{$tableId}", 5)`):**
  1. Kiểm tra sức chứa bàn: `$guestCount <= $table->suc_chua` $\rightarrow$ Nếu vượt quá ném `400 Bad Request`.
  2. Kiểm tra quy tắc báo trước: Phải $\ge 30$ phút. Nếu có từ khóa đặc biệt (*"vip", "sinh nhật"...*) phải $\ge 120$ phút.
  3. Kiểm tra xung đột khung giờ: Truy vấn theo công thức Carbon $\pm 119$ phút. Nếu bị trùng ném `409 Conflict` (*"Bàn đã có người đặt trong khung giờ này"*).
  4. Tạo bản ghi `DAT_BAN` trạng thái `PENDING` (`Chờ xử lý`).
- **Response `201 Created`:** `ReservationResource`.

---

### UC BKG-03 & BKG-04: Sửa / Hủy Lịch Hẹn Đặt Bàn (Quy Tắc 60 Phút)
- **Endpoint:** `PUT /reservations/{id}` (Sửa) hoặc `PUT /reservations/{id}/cancel` (Hủy).
- **Luồng thực thi kiểm tra:**
  ```php
  $diffInMinutes = now()->diffInMinutes($reservation->thoi_gian, false);
  if ($diffInMinutes < 60) {
      throw new ConflictHttpException('Chỉ được phép sửa hoặc hủy đặt bàn trước giờ hẹn tối thiểu 60 phút');
  }
  ```
- Nếu hủy: chuyển `trang_thai = CANCELLED` và giải phóng bàn về `AVAILABLE`.

---

### UC BKG-11: Admin Phê Duyệt / Cập Nhật Trạng Thái Đặt Bàn
- **Actor:** Quản trị viên (`ADMIN,STAFF`).
- **Endpoint:** `PATCH /api/v1/admin/reservations/{id}/status`
- **Validation:** `targetStatus`: `required|in:CONFIRMED,OCCUPIED,COMPLETED,CANCELLED,REJECTED`.
- **Luồng thực thi:**
  1. Cập nhật `DAT_BAN.trang_thai = $targetStatus`.
  2. Tự động đồng bộ trạng thái của `BAN_AN` tương ứng theo Bảng ma trận tại Mục 3.4.
  3. Trả về `AdminReservationResource` kèm thông báo thành công.

---

### UC BKG-13: Khách Hàng Mock Thanh Toán Cọc Thành Công
- **Actor:** Khách hàng (`CUSTOMER`).
- **Endpoint:** `POST /api/v1/customer/reservations/{id}/mock-deposit`
- **Luồng thực thi:**
  1. Kiểm tra đơn đặt bàn thuộc quyền sở hữu của khách hàng và đang ở trạng thái `PENDING_DEPOSIT`.
  2. Kiểm tra thời hạn cọc: nếu `han_chot_coc` đã qua thời gian hiện tại $\rightarrow$ tự động hủy phiếu (`CANCELLED`) và ném lỗi `400 Bad Request`.
  3. Cập nhật `da_thanh_toan_coc = true`, chuyển trạng thái `trang_thai = CONFIRMED`.
  4. Đồng bộ trạng thái bàn ăn sang `RESERVED` (`Đã đặt`).
  5. Trả về `ReservationResource` (200 OK).

---

### UC BKG-14: Nhân Viên / Admin Xác Nhận Nhận Cọc Thủ Công
- **Actor:** Quản trị viên / Nhân viên (`ADMIN,STAFF`).
- **Endpoint:** `POST /api/v1/admin/reservations/{id}/confirm-deposit`
- **Luồng thực thi:**
  1. Tìm đơn đặt bàn theo `{id}`.
  2. Cập nhật `da_thanh_toan_coc = true`, `trang_thai = CONFIRMED`.
  3. Đồng bộ bàn sang `RESERVED`.
  4. Trả về `AdminReservationResource` (200 OK).
