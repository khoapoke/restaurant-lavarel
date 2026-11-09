# 📦 ĐẶC TẢ API MODULE: ORDER (ĐƠN HÀNG & BẾP CHẾ BIẾN)

> **Namespace dự kiến:** `App\Modules\Order\`  
> **Controllers:** `Customer\OrderController`, `Admin\OrderController`  
> **Models:** `Order` (`DON_HANG`), `OrderDetail` (`CHI_TIET_DON_HANG`)  
> **Redis Lock:** `lock:order:checkout:{accountId}` (10s)

---

## 1. CUSTOMER ORDER APIS

### 1.1. Tạo đơn hàng từ giỏ (Checkout) (`ORD-01`)
- **Method & URL:** `POST /api/v1/customer/orders/checkout`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Redis Lock:** `lock:order:checkout:{accountId}` (10s, block 5s chống double-click)
- **Request Body:**
```json
{
  "fulfillmentType": "DELIVERY",
  "recipientName": "Lê Hoàng Hùng",
  "recipientPhone": "0923456789",
  "shippingAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
  "paymentMethod": "BANK_TRANSFER_QR",
  "orderNote": "Gọi điện khi tới sảnh"
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Order created successfully",
  "data": {
    "orderId": 4,
    "orderDate": "2026-06-10T10:15:00",
    "status": "PENDING_CONFIRMATION",
    "totalAmount": 650000.00,
    "shippingAddress": "[DELIVERY] Lê Hoàng Hùng - 0923456789 | 789 Cách Mạng Tháng 8, Quận 3, TP.HCM | Note: Gọi điện khi tới sảnh",
    "payment": {
      "paymentId": 4,
      "amount": 650000.00,
      "paymentMethod": "BANK_TRANSFER_QR",
      "status": "UNPAID"
    },
    "items": [
      {
        "dishId": 1,
        "dishName": "Súp bào ngư",
        "quantity": 2,
        "unitPrice": 150000.00,
        "subTotal": 300000.00
      },
      {
        "dishId": 2,
        "dishName": "Bò sốt tiêu đen",
        "quantity": 1,
        "unitPrice": 350000.00,
        "subTotal": 350000.00
      }
    ]
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Cannot checkout an empty cart",
  "errors": null
}
```
- **Response JSON Sample (Error - 429 Too Many Requests - Redis Lock Timeout):**
```json
{
  "success": false,
  "code": 429,
  "message": "Order is currently being processed. Please wait a moment."
}
```

---

### 1.2. Khách hủy đơn hàng (`ORD-02`)
- **Method & URL:** `POST /api/v1/customer/orders/{orderId}/cancel`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Request Body:**
```json
{
  "cancelReason": "Đổi ý muốn chọn món khác"
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Order cancelled successfully",
  "data": {
    "orderId": 4,
    "status": "CANCELLED"
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Order cannot be cancelled because it is already being prepared by the kitchen",
  "errors": null
}
```
- **Ghi chú:** Khách chỉ được hủy khi đơn ở trạng thái `PENDING_CONFIRMATION`.

---

### 1.3. Lịch sử đơn hàng của khách (`ORD-04`)
- **Method & URL:** `GET /api/v1/customer/orders?status=COMPLETED&page=1`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Orders retrieved successfully",
  "data": [
    {
      "orderId": 4,
      "orderDate": "2026-06-10T10:15:00",
      "status": "COMPLETED",
      "totalAmount": 650000.00,
      "itemCount": 2
    }
  ],
  "meta": {
    "currentPage": 1,
    "perPage": 10,
    "total": 1,
    "lastPage": 1
  }
}
```

---

## 2. ADMIN ORDER MANAGEMENT APIS

### 2.1. Cập nhật trạng thái quy trình đơn hàng (`ORD-08`)
- **Method & URL:** `PATCH /api/v1/admin/orders/{orderId}/status`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Request Body:**
```json
{
  "targetStatus": "PREPARING"
}
```
- **Luồng trạng thái hợp lệ (FSM):**
  `PENDING_CONFIRMATION` $\rightarrow$ `CONFIRMED` $\rightarrow$ `PREPARING` $\rightarrow$ `DELIVERING` $\rightarrow$ `COMPLETED`
  *(Bất kỳ trạng thái nào trước `COMPLETED` đều có thể chuyển sang `CANCELLED`)*.
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Order status updated successfully",
  "data": {
    "orderId": 2,
    "previousStatus": "CONFIRMED",
    "currentStatus": "PREPARING"
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Invalid status transition from COMPLETED to PREPARING",
  "errors": null
}
```

