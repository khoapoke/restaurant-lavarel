# 🔐 ĐẶC TẢ API MODULE: AUTH & QUẢN LÝ TÀI KHOẢN (AUTHENTICATION & ACCOUNT)

> **Namespace dự kiến:** `App\Modules\Auth\`  
> **Controllers:** `AuthController`, `Customer\ProfileController`, `Admin\AccountController`  
> **Models:** `Account` (`TAI_KHOAN`), `UserProfile` (`NGUOI_DUNG`), `Role` (`VAI_TRO`)

---

## 1. SHARED AUTHENTICATION APIS

### 1.1. Đăng ký tài khoản Khách hàng (`USR-01`)
- **Method & URL:** `POST /api/v1/auth/register`
- **Controller & FormRequest:** `AuthController@register` | `RegisterRequest`
- **Headers:** `Accept: application/json`, `Content-Type: application/json`
- **Request Body:**
```json
{
  "username": "khachhang_moi",
  "password": "Password@123",
  "passwordConfirmation": "Password@123",
  "email": "khachhang_moi@gmail.com",
  "fullName": "Nguyễn Minh Tuấn",
  "phoneNumber": "0987654321",
  "birthDate": "1999-08-15",
  "address": "140 Lê Trọng Tấn, Quận Tân Phú, TP.HCM"
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Account registered successfully",
  "data": {
    "accessToken": "1|laravel_sanctum_token_string_example_abc123",
    "tokenType": "Bearer",
    "account": {
      "accountId": 5,
      "username": "khachhang_moi",
      "email": "khachhang_moi@gmail.com",
      "status": "ACTIVE",
      "role": "CUSTOMER",
      "userProfile": {
        "userId": 5,
        "fullName": "Nguyễn Minh Tuấn",
        "phoneNumber": "0987654321",
        "birthDate": "1999-08-15",
        "address": "140 Lê Trọng Tấn, Quận Tân Phú, TP.HCM"
      },
      "cartId": 4
    }
  }
}
```
- **Response JSON Sample (Error - 422 Unprocessable Entity):**
```json
{
  "success": false,
  "code": 422,
  "message": "The given data was invalid.",
  "errors": {
    "username": [
      "The username has already been taken."
    ],
    "phoneNumber": [
      "The phone number format is invalid."
    ]
  }
}
```
- **Ghi chú nghiệp vụ:** Validate `unique:TAI_KHOAN,ten_dang_nhap` và `unique:TAI_KHOAN,email`. Bọc trong `DB::transaction()` tạo tuần tự `UserProfile` $\rightarrow$ `Account` (băm BCrypt) $\rightarrow$ `Cart`.

---

### 1.2. Đăng nhập hệ thống (`USR-02`)
- **Method & URL:** `POST /api/v1/auth/login`
- **Controller & FormRequest:** `AuthController@login` | `LoginRequest`
- **Request Body:**
```json
{
  "username": "kh1",
  "password": "123",
  "rememberMe": true
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Login successful",
  "data": {
    "accessToken": "2|sanctum_token_xyz987654321",
    "tokenType": "Bearer",
    "expiresAt": "2026-07-10T08:05:00Z",
    "account": {
      "accountId": 3,
      "username": "kh1",
      "email": "khachhang1@gmail.com",
      "role": "CUSTOMER",
      "status": "ACTIVE",
      "userProfile": {
        "userId": 3,
        "fullName": "Lê Hoàng Hùng",
        "phoneNumber": "0923456789",
        "address": "789 Cách Mạng Tháng 8"
      }
    }
  }
}
```
- **Response JSON Sample (Error - 401 Unauthorized / 403 Forbidden):**
```json
{
  "success": false,
  "code": 401,
  "message": "Invalid credentials or account has been locked",
  "errors": null
}
```
- **Ghi chú nghiệp vụ:** Tự động nâng cấp mật khẩu plaintext `'123'` sang `BCrypt` an toàn ngay trong lần đầu đăng nhập. Cấp Sanctum Token có thời hạn và ability theo role (`role:admin` hoặc `role:customer`).

---

### 1.3. Đăng xuất hệ thống (`USR-03`)
- **Method & URL:** `POST /api/v1/auth/logout`
- **Middleware:** `auth:sanctum`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Logged out successfully",
  "data": null
}
```
- **Ghi chú nghiệp vụ:** Thực thi `$request->user()->currentAccessToken()->delete()`.

---

## 2. CUSTOMER PROFILE APIS

### 2.1. Xem hồ sơ cá nhân (`USR-04`)
- **Method & URL:** `GET /api/v1/customer/profile`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Profile retrieved successfully",
  "data": {
    "accountId": 3,
    "username": "kh1",
    "email": "khachhang1@gmail.com",
    "userId": 3,
    "fullName": "Lê Hoàng Hùng",
    "birthDate": "1995-12-01",
    "phoneNumber": "0923456789",
    "defaultAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
    "savedAddresses": [
      {
        "addressId": "addr_1",
        "recipientName": "Lê Hoàng Hùng",
        "recipientPhone": "0923456789",
        "fullAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
        "isDefault": true
      }
    ],
    "allergies": ["PEANUT", "SHELLFISH"],
    "dietaryPreferences": ["LOW_SUGAR"]
  }
}
```

---

### 2.2. Cập nhật hồ sơ, địa chỉ nhận hàng & dị ứng (`USR-05`)
- **Method & URL:** `PUT /api/v1/customer/profile`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Request Body:**
```json
{
  "fullName": "Lê Hoàng Hùng",
  "birthDate": "1995-12-01",
  "phoneNumber": "0923456789",
  "email": "khachhang1_new@gmail.com",
  "defaultAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
  "savedAddresses": [
    {
      "addressId": "addr_1",
      "recipientName": "Lê Hoàng Hùng",
      "recipientPhone": "0923456789",
      "fullAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
      "isDefault": true
    }
  ],
  "allergies": ["PEANUT", "SHELLFISH"],
  "dietaryPreferences": ["HIGH_PROTEIN"]
}
```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Profile updated successfully",
  "data": {
    "accountId": 3,
    "email": "khachhang1_new@gmail.com",
    "fullName": "Lê Hoàng Hùng",
    "phoneNumber": "0923456789",
    "defaultAddress": "789 Cách Mạng Tháng 8, Quận 3, TP.HCM",
    "allergies": ["PEANUT", "SHELLFISH"],
    "dietaryPreferences": ["HIGH_PROTEIN"]
  }
}
```

---

## 3. ADMIN ACCOUNT MANAGEMENT APIS

### 3.1. Danh sách tài khoản Admin (`ADM-01`)
- **Method & URL:** `GET /api/v1/admin/accounts?keyword=Hung&status=ACTIVE&page=1`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Accounts retrieved successfully",
  "data": [
    {
      "accountId": 3,
      "username": "kh1",
      "email": "khachhang1@gmail.com",
      "status": "ACTIVE",
      "role": "CUSTOMER",
      "fullName": "Lê Hoàng Hùng",
      "phoneNumber": "0923456789"
    }
  ],
  "meta": {
    "currentPage": 1,
    "perPage": 10,
    "total": 5,
    "lastPage": 1
  }
}
```

---

### 3.2. Khóa / Mở khóa tài khoản (`ADM-05`)
- **Method & URL:** 
  - Khóa: `PATCH /api/v1/admin/accounts/{accountId}/lock`
  - Mở khóa: `PATCH /api/v1/admin/accounts/{accountId}/unlock`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Account locked successfully",
  "data": {
    "accountId": 3,
    "username": "kh1",
    "email": "khachhang1@gmail.com",
    "status": "LOCKED",
    "roleName": "Khách hàng",
    "fullName": "Lê Hoàng Hùng",
    "phoneNumber": "0923456789"
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "Admin cannot lock their own account",
  "errors": null
}
```
- **Ghi chú nghiệp vụ:** Rule `AUTH-LOCK`: Admin không được tự khóa chính mình. Khi khóa tài khoản, lập tức xóa token `$targetAccount->tokens()->delete()` để thu hồi phiên đăng nhập ngay tức khắc.

