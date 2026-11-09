# 📈 ĐẶC TẢ API MODULE: REPORT (BÁO CÁO THỐNG KÊ & DASHBOARD)

> **Namespace dự kiến:** `App\Modules\Report\`  
> **Controller:** `Admin\ReportController`  
> **Service:** `Admin\ReportService`  
> **Phân quyền:** Toàn bộ endpoints yêu cầu quyền `ADMIN`.

---

## 1. Dashboard Tổng Quan Hệ Thống (`RPT-00`)
- **Method & URL:** `GET /api/v1/admin/reports/dashboard?fromDate=2026-06-01&toDate=2026-06-30`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Query Params:**
  - `fromDate` (`date:Y-m-d`, optional, mặc định là ngày đầu tháng).
  - `toDate` (`date:Y-m-d`, optional, mặc định là ngày hiện tại, phải $\ge$ `fromDate`).
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dashboard report generated successfully",
  "data": {
    "revenue": {
      "totalPaidRevenue": 785000.00,
      "dailySeries": [
        { "date": "2026-06-01", "amount": 650000.00 },
        { "date": "2026-06-02", "amount": 135000.00 }
      ]
    },
    "orders": {
      "totalCount": 3,
      "completedCount": 1,
      "cancelledCount": 0,
      "completionRate": 33.33
    },
    "topSellingDishes": [
      { "dishId": 4, "dishName": "Trà đào cam sả", "totalSold": 3, "revenue": 135000.00 },
      { "dishId": 1, "dishName": "Súp bào ngư", "totalSold": 2, "revenue": 300000.00 }
    ],
    "reservations": {
      "totalBookings": 3,
      "confirmedBookings": 2,
      "occupancyRatePercent": 50.00
    }
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity):**
```json
{
  "success": false,
  "code": 422,
  "message": "Invalid date range",
  "errors": {
    "toDate": [
      "The to date must be a date after or equal to from date."
    ]
  }
}
```

---

## 2. Báo Cáo Doanh Thu Theo Chuỗi Ngày (`RPT-01`)
- **Method & URL:** `GET /api/v1/admin/reports/revenue?fromDate=2026-06-01&toDate=2026-06-30`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Revenue report generated successfully",
  "data": {
    "totalPaidRevenue": 785000.00,
    "dailySeries": [
      { "date": "2026-06-01", "amount": 650000.00 },
      { "date": "2026-06-02", "amount": 135000.00 }
    ]
  }
}
```
- **Ghi chú tính toán:** Chỉ tính các giao dịch có `THANH_TOAN.trang_thai = 'Đã thanh toán'` (`PAID`).

---

## 3. Báo Cáo Tình Hình Đơn Hàng (`RPT-02`)
- **Method & URL:** `GET /api/v1/admin/reports/orders?fromDate=2026-06-01&toDate=2026-06-30`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Orders report generated successfully",
  "data": {
    "totalCount": 3,
    "completedCount": 1,
    "cancelledCount": 0,
    "completionRate": 33.33
  }
}
```

---

## 4. Top Món Ăn Bán Chạy Nhất (`RPT-03`)
- **Method & URL:** `GET /api/v1/admin/reports/top-dishes?fromDate=2026-06-01&toDate=2026-06-30&limit=5`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Top selling dishes report generated successfully",
  "data": [
    {
      "dishId": 4,
      "dishName": "Trà đào cam sả",
      "totalSold": 3,
      "revenue": 135000.00
    },
    {
      "dishId": 1,
      "dishName": "Súp bào ngư",
      "totalSold": 2,
      "revenue": 300000.00
    }
  ]
}
```

---

## 5. Báo Cáo Tình Hình Đặt Bàn & Tỷ Lệ Lấp Đầy (`RPT-04`)
- **Method & URL:** `GET /api/v1/admin/reports/reservations?fromDate=2026-06-01&toDate=2026-06-30`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Reservations report generated successfully",
  "data": {
    "totalBookings": 3,
    "confirmedBookings": 2,
    "occupancyRatePercent": 50.00
  }
}
```

