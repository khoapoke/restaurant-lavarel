# 💳 ĐẶC TẢ API MODULE: PAYMENT (THANH TOÁN & ĐỐI SOÁT VIETQR)

> **Namespace dự kiến:** `App\Modules\Payment\`  
> **Controllers:** `Customer\PaymentController`, `Admin\PaymentController`  
> **Model:** `Payment` (`THANH_TOAN`)  
> **Cổng thanh toán:** Tích hợp VietQR Napas 247

---

## 1. CUSTOMER PAYMENT APIS

### 1.1. Sinh mã VietQR động thanh toán đơn hàng (`PAY-04`)
- **Method & URL:** `POST /api/v1/customer/payments/{orderId}/qr`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "QR code generated successfully",
  "data": {
    "paymentId": 4,
    "orderId": 4,
    "amount": 650000.00,
    "paymentMethod": "BANK_TRANSFER_QR",
    "status": "UNPAID",
    "qrImageUrl": "https://img.vietqr.io/image/970403-060269723389-compact2.png?amount=650000&addInfo=DH4%20THANH%20TOAN",
    "bankBin": "970403",
    "accountNo": "060269723389",
    "transferContent": "DH4 THANH TOAN",
    "expiresAt": "2026-06-10T10:30:00Z"
  }
}
```
- **Response JSON Sample (Error - 409 Conflict):**
```json
{
  "success": false,
  "code": 409,
  "message": "Order has already been paid",
  "errors": null
}
```

---

### 1.2. Kiểm tra trạng thái thanh toán đơn hàng (`PAY-06`)
- **Method & URL:** `GET /api/v1/customer/payments/{orderId}/status`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Payment status checked successfully",
  "data": {
    "orderId": 4,
    "paymentId": 4,
    "amount": 650000.00,
    "status": "PAID",
    "paymentDate": "2026-06-10T10:20:15Z"
  }
}
```

---

### 1.3. Webhook nhận biến động số dư VietQR (`PAY-07`)
- **Method & URL:** `POST /api/v1/webhooks/payment-callback`
- **Public Endpoint:** Được gọi từ ngân hàng hoặc cổng trung gian (SePay / PayOS)
- **Request Body:**
```json
{
  "gateway": "VIETQR",
  "transactionDate": "2026-06-10 10:20:15",
  "accountNumber": "060269723389",
  "transferType": "in",
  "transferAmount": 650000,
  "content": "DH4 THANH TOAN",
  "referenceCode": "FT2616239102"
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Payment webhook processed successfully",
  "data": {
    "orderId": 4,
    "status": "PAID",
    "amountPaid": 650000.00
  }
}
```
- **Ghi chú xử lý:** Tự động tách `orderId` từ nội dung chuyển khoản `DH{orderId}`, đối chiếu số tiền thanh toán $\ge$ tổng tiền đơn hàng, cập nhật `THANH_TOAN.trang_thai = PAID` và `DON_HANG.trang_thai = CONFIRMED`.

---

## 2. ADMIN PAYMENT APIS

### 2.1. Tra cứu danh sách giao dịch thanh toán (`PAY-01`)
- **Method & URL:** `GET /api/v1/admin/payments?status=PAID&page=1`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Payments retrieved successfully",
  "data": [
    {
      "paymentId": 4,
      "orderId": 4,
      "amount": 650000.00,
      "paymentMethod": "BANK_TRANSFER_QR",
      "status": "PAID",
      "paymentDate": "2026-06-10 10:20:15",
      "customerName": "Lê Hoàng Hùng"
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

### 2.2. Ghi nhận hoàn tiền giao dịch (`PAY-06`)
- **Method & URL:** `POST /api/v1/admin/payments/{paymentId}/refund`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Request Body:**
```json
{
  "refundReason": "Nhà hàng hết nguyên liệu đột xuất"
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Refund recorded successfully",
  "data": {
    "paymentId": 2,
    "orderId": 2,
    "amount": 135000.00,
    "status": "REFUNDED",
    "refundReason": "Nhà hàng hết nguyên liệu đột xuất"
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Only PAID transactions can be refunded",
  "errors": null
}
```

