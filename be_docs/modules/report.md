# 📈 MODULE: REPORT – Business Logic & API Spec

> **Vị trí thư mục:** `app/Modules/Report/`  
> **Namespace:** `App\Modules\Report\`  
> **Phiên bản:** 2.0 (Streamlined Edition)

---

## 1. Tổng quan & Trách nhiệm

Module **Report** cung cấp các báo cáo phân tích kinh doanh và Dashboard điều hành dành riêng cho Quản trị viên (`ADMIN`):
- **Executive Dashboard (RPT-00):** Gom cả 4 nhóm chỉ số vào 1 request duy nhất.
- **Doanh thu thực thu (RPT-01):** Thống kê dòng tiền thực từ các giao dịch `PAID` kèm chuỗi theo ngày (`dailySeries`).
- **Thống kê Đơn hàng (RPT-02):** Số đơn hoàn tất, số đơn hủy và tỷ lệ thành công (`completionRate`).
- **Top Món ăn bán chạy (RPT-03):** Xếp hạng món theo số lượng bán và doanh thu (dựa trên snapshot `don_gia`).
- **Hiệu suất Bàn ăn (RPT-04):** Số lượt đặt bàn và tỷ lệ lấp đầy bàn ăn (`occupancyRatePercent`).

---

## 2. Thành phần kỹ thuật

| Thành phần | Lớp / Đường dẫn thực tế | Trách nhiệm |
| :--- | :--- | :--- |
| **Service** | `Admin\ReportService` | Thực thi các truy vấn SQL Aggregation trực tiếp (`DB::table`) với `SUM`, `COUNT`, `GROUP BY` hiệu năng cao. |
| **Controller**| `Admin\ReportController` | Tiếp nhận request và điều phối trả về qua `ApiResponse`. |
| **Request** | `Admin\DashboardReportRequest` | Validate `fromDate`, `toDate` (`Y-m-d`), `toDate >= fromDate`, `limit` (1–50). |
| **Routes** | `app/Modules/Report/Routes/api.php` | Định tuyến `/api/v1/admin/reports/*` (`auth:sanctum`, `role:ADMIN`). |

---

## 3. Domain Rules Bất Biến

1. **High-Performance Aggregates (Tránh Memory Leak):** Tuyệt đối không dùng `Order::all()` hay hydrate Eloquent Models. 100% sử dụng `DB::table(...)` để MySQL Engine thực hiện tính toán.
2. **Doanh thu thực thu:** Chỉ cộng dồn khi `THANH_TOAN.trang_thai = 'Đã thanh toán'` (`PaymentStatus::PAID`).
3. **Món bán chạy:** Chỉ tính các đơn không bị hủy (`DON_HANG.trang_thai != 'Đã hủy'`), dùng snapshot `so_luong * don_gia` từ bảng `CHI_TIET_DON_HANG`.
4. **Mặc định tham số thời gian:**
   - `fromDate`: Mặc định đầu tháng hiện tại (`startOfMonth`).
   - `toDate`: Mặc định ngày hiện tại (`today`).
   - `limit`: Mặc định 5 (khoảng từ 1 đến 50).

---

## 4. Schemas Dữ Liệu Chuẩn Dành Cho Frontend

### Schema 4.1: Dashboard Tổng Quan (`RPT-00`)
```json
{
  "period": { "fromDate": "2026-11-01", "toDate": "2026-11-13" },
  "revenue": {
    "totalRevenue": 45200000,
    "dailySeries": [
      { "date": "2026-11-10", "revenue": 5200000 },
      { "date": "2026-11-11", "revenue": 6800000 }
    ]
  },
  "orders": {
    "totalOrders": 120,
    "completedOrders": 105,
    "cancelledOrders": 15,
    "completionRate": 87.5
  },
  "topDishes": [
    { "dishId": 5, "dishName": "Gỏi ngó sen tôm thịt", "totalSold": 85, "revenue": 7225000 }
  ],
  "reservations": {
    "totalReservations": 45,
    "confirmedReservations": 38,
    "totalTables": 15,
    "occupancyRatePercent": 63.3
  }
}
```

---

## 5. Bảng Tổng Hợp 5 Endpoints Báo Cáo Admin

| Mã API | Method | Endpoint URL | Query Parameters | Response Metrics Trả Về | Mã HTTP |
| :---: | :---: | :--- | :--- | :--- | :---: |
| `RPT-00` | `GET` | `/api/v1/admin/reports/dashboard` | `fromDate`, `toDate`, `limit` | Tổng hợp cả 4 nhóm chỉ số (Schema 4.1) | `200 / 422` |
| `RPT-01` | `GET` | `/api/v1/admin/reports/revenue` | `fromDate`, `toDate` | `totalRevenue` và mảng `dailySeries` | `200 / 422` |
| `RPT-02` | `GET` | `/api/v1/admin/reports/orders` | `fromDate`, `toDate` | `totalOrders`, `completedOrders`, `completionRate` | `200 / 422` |
| `RPT-03` | `GET` | `/api/v1/admin/reports/top-dishes` | `fromDate`, `toDate`, `limit` | Danh sách xếp hạng món ăn bán chạy nhất | `200 / 422` |
| `RPT-04` | `GET` | `/api/v1/admin/reports/reservations` | `fromDate`, `toDate` | Số lượt đặt bàn và tỷ lệ lấp đầy bàn ăn | `200 / 422` |
