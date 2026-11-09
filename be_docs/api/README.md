# 🌐 ĐẶC TẢ CHUẨN GIAO TIẾP API (API PROTOCOL SPECIFICATION)
## Chuẩn Hóa Response Envelopes, Mã Lỗi HTTP & Cơ Chế Xử Lý Lỗi

> **Áp dụng cho:** Toàn bộ API Endpoints tại `/api/v1/*`  
> **Tài liệu phối hợp:** Backend Service (Laravel 11) & Frontend Clients (React, Next.js, Mobile App)

---

## 📚 0. Danh Mục Đặc Tả API Theo Từng Module (Module API Specs)

| STT | Tên Module | File Đặc Tả Chi Tiết | Phạm vi nghiệp vụ |
| :---: | :--- | :--- | :--- |
| **1** | **`Auth`** | [`auth.md`](auth.md) | Đăng ký, Đăng nhập, Hồ sơ cá nhân, Quản lý tài khoản Admin |
| **2** | **`Menu`** | [`menu.md`](menu.md) | Danh mục món, Danh sách món ăn, Lọc & Gợi ý thời tiết, Quản trị thực đơn |
| **3** | **`Cart`** | [`cart.md`](cart.md) | Xem giỏ hàng, Thêm/Sửa/Xóa món ăn trong giỏ hàng |
| **4** | **`Order`** | [`order.md`](order.md) | Checkout tạo đơn, Hủy đơn, Lịch sử đơn hàng, FSM chuyển trạng thái bếp |
| **5** | **`Reservation`** | [`reservation.md`](reservation.md) | Tra cứu bàn trống, Đặt bàn, Sửa/Hủy bàn, Quản lý bàn ăn Admin |
| **6** | **`Payment`** | [`payment.md`](payment.md) | Sinh mã VietQR, Đối soát giao dịch, Hoàn tiền, Webhook biến động số dư |
| **7** | **`Report`** | [`report.md`](report.md) | Dashboard tổng quan, Báo cáo Doanh thu, Đơn hàng, Món bán chạy, Lấp đầy bàn |

---

## 📌 1. Nguyên Tắc Thiết Kế Chung (Design Principles)

1. **Chuẩn hóa Phản hồi (Unified Envelope):** Toàn bộ API trả về cấu trúc JSON nhất quán gồm các trường cấp cao (`success`, `code`, `message`, `data`/`errors`).
2. **Không trả về HTML Error:** Nhờ middleware `ForceJsonResponse` và Exception Handler tùy biến trong `bootstrap/app.php`, API **100% không bao giờ trả về trang HTML 401/404/500** hoặc redirect về trang login web.
3. **Che chắn Ngoại lệ An toàn (Global Exception Shielding):** Các thông báo lỗi nhạy cảm như file path, số dòng code, chuỗi truy vấn SQL (`PDOException`) được che chắn an toàn, chỉ trả về thông điệp nghiệp vụ thân thiện cho Client.

---

## 📨 2. Yêu Cầu Request Headers Từ Client

Khi gửi request từ Frontend (Axios / Fetch), Client **BẮT BUỘC** đính kèm các Header sau:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <sanctum_access_token>
```

> [!IMPORTANT]
> **Tầm quan trọng của Header `Accept: application/json`:**  
> Laravel nhận diện loại Client thông qua header này. Mặc dù hệ thống đã có middleware `ForceJsonResponse` để ép định dạng, Client luôn nên chủ động khai báo header này trong Axios Request Interceptor để đảm bảo trải nghiệm thống nhất.

---

## ✅ 3. Cấu Trúc Phản Hồi Thành Công (Success Response Envelopes)

### 3.1. Phản hồi Dữ liệu Đơn hoặc Thao tác Thành công (`200 OK`)
Áp dụng cho các thao tác truy vấn chi tiết, cập nhật hoặc xóa dữ liệu thành công:

```json
{
  "success": true,
  "code": 200,
  "message": "Dishes retrieved successfully",
  "data": {
    "dishId": 1,
    "dishName": "Gỏi ngó sen tôm thịt",
    "price": 85000,
    "imageUrl": "https://example.com/images/goi-ngo-sen.jpg",
    "isAvailable": true
  }
}
```

### 3.2. Phản hồi Tạo mới Tài nguyên (`201 Created`)
Áp dụng khi đăng ký tài khoản (`USR-01`), tạo đơn hàng (`ORD-01`), đặt bàn (`BKG-02`), thêm món (`MNU-13`):

```json
{
  "success": true,
  "code": 201,
  "message": "Resource created successfully",
  "data": {
    "orderId": 105,
    "orderDate": "2026-11-10 12:30:00",
    "totalAmount": 320000,
    "status": "PENDING_CONFIRMATION"
  }
}
```

### 3.3. Phản hồi Danh sách Phân trang (Paginated List Envelope)
Áp dụng cho danh sách món ăn (`MNU-02`), lịch sử đơn hàng (`ORD-04`), lịch sử đặt bàn (`BKG-05`), danh sách tài khoản Admin (`ADM-01`):

```json
{
  "success": true,
  "code": 200,
  "message": "Orders retrieved successfully",
  "data": [
    {
      "orderId": 101,
      "orderDate": "2026-11-09 19:15:00",
      "totalAmount": 250000,
      "status": "COMPLETED"
    },
    {
      "orderId": 102,
      "orderDate": "2026-11-10 11:45:00",
      "totalAmount": 180000,
      "status": "DELIVERING"
    }
  ],
  "meta": {
    "currentPage": 1,
    "perPage": 10,
    "total": 35,
    "lastPage": 4
  },
  "links": {
    "first": "http://localhost:8000/api/v1/customer/orders?page=1",
    "last": "http://localhost:8000/api/v1/customer/orders?page=4",
    "prev": null,
    "next": "http://localhost:8000/api/v1/customer/orders?page=2"
  }
}
```

---

## ❌ 4. Bảng Mã Lỗi HTTP & Cấu Trúc Phản Hồi Lỗi (Error Envelopes)

Mọi phản hồi lỗi đều tuân thủ cấu trúc envelope:
```json
{
  "success": false,
  "code": <HTTP_STATUS_CODE>,
  "message": "<Mô tả lỗi tổng quan>",
  "errors": <Chi tiết lỗi (đối tượng key-value hoặc null)>
}
```

### 4.1. Bảng tra cứu Mã Lỗi HTTP (HTTP Status Matrix)

| Mã HTTP | Tên gọi chuẩn | Nguyên nhân nghiệp vụ | Nguồn phát sinh trong Laravel |
| :---: | :--- | :--- | :--- |
| **`400`** | `Bad Request` | Dữ liệu hoặc logic yêu cầu không hợp lệ (Ví dụ: `minPrice > maxPrice`, giỏ hàng rỗng không thể checkout). | `HttpException(400, ...)` |
| **`401`** | `Unauthorized` | Token chưa gửi, sai token, token hết hạn hoặc nhập sai thông tin đăng nhập. | `AuthenticationException` |
| **`403`** | `Forbidden` | Không có quyền (Ví dụ: Khách hàng gọi API Admin) hoặc tài khoản bị khóa (`LOCKED`). | `AuthorizationException`, Middleware `CheckAccountActive`, `CheckRole` |
| **`404`** | `Not Found` | Không tìm thấy tài nguyên (Món ăn, đơn hàng, bàn ăn không tồn tại hoặc đã bị xóa mềm). | `ModelNotFoundException`, `NotFoundHttpException` |
| **`409`** | `Conflict` | Xung đột trạng thái nghiệp vụ (Bàn đã có người đặt trước trong khung giờ $\pm 119$ phút, hủy đơn khi bếp đã nấu xong). | `HttpException(409, ...)` |
| **`422`** | `Unprocessable Entity` | Dữ liệu gửi lên không vượt qua được FormRequest Validation (Ví dụ: Email trùng, thiếu mật khẩu, số điện thoại sai định dạng). | `ValidationException` |
| **`429`** | `Too Many Requests` | Vượt quá số lần gọi API hoặc hệ thống đang khóa tài nguyên xử lý (Redis Distributed Lock timeout khi double-click đặt hàng). | `ThrottleRequests`, `LockTimeoutException` |
| **`500`** | `Internal Server Error` | Lỗi máy chủ không lường trước. | `Throwable` được bắt trong Exception Handler |

---

### 4.2. Mẫu Phản Hồi Lỗi Cho Từng Trường Hợp Cụ Thể

#### A. Lỗi Xác thực Dữ liệu Đầu vào (`422 Unprocessable Entity`)
Cung cấp chi tiết danh sách lỗi theo từng trường dữ liệu để Frontend hiển thị thông báo dưới input tương ứng:
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
    ],
    "passwordConfirmation": [
      "The password confirmation field must match password."
    ]
  }
}
```

#### B. Lỗi Chưa Đăng nhập hoặc Token Hết hạn (`401 Unauthorized`)
```json
{
  "success": false,
  "code": 401,
  "message": "Unauthenticated or token is missing/invalid"
}
```
*(Nếu nhập sai tài khoản hoặc mật khẩu khi đăng nhập `POST /auth/login`):*
```json
{
  "success": false,
  "code": 401,
  "message": "Invalid credentials or account has been locked"
}
```

#### C. Lỗi Quyền Hạn hoặc Tài Khoản Bị Khóa (`403 Forbidden`)
```json
{
  "success": false,
  "code": 403,
  "message": "Your account has been locked by Administrator"
}
```
*(Khi tài khoản vai trò `CUSTOMER` cố tình truy cập endpoint của `ADMIN`):*
```json
{
  "success": false,
  "code": 403,
  "message": "Unauthorized. Requires role: ADMIN"
}
```

#### D. Lỗi Không Tìm Thấy Dữ Liệu (`404 Not Found`)
```json
{
  "success": false,
  "code": 404,
  "message": "Dish not found or unavailable"
}
```

#### E. Lỗi Xung Đột Nghiệp Vụ (`409 Conflict`)
Áp dụng khi kiểm tra lịch đặt bàn bị trùng:
```json
{
  "success": false,
  "code": 409,
  "message": "Table is already reserved for the selected time slot"
}
```
Hoặc khi hủy/sửa bàn quá muộn:
```json
{
  "success": false,
  "code": 409,
  "message": "Cannot modify or cancel reservation less than 60 minutes before scheduled time"
}
```

#### F. Lỗi Thao Tác Quá Nhanh / Khóa Phân Tán Đang Bận (`429 Too Many Requests`)
Áp dụng khi người dùng double-click nút Thanh toán:
```json
{
  "success": false,
  "code": 429,
  "message": "Order is currently being processed. Please wait a moment."
}
```

#### G. Lỗi Nội Bộ Máy Chủ (`500 Internal Server Error`)
```json
{
  "success": false,
  "code": 500,
  "message": "Internal server error occurred. Please contact administrator."
}
```

---

## 💻 5. Hướng Dẫn Tích Hợp Frontend (Axios Interceptors Sample)

Đoạn mã mẫu cấu hình Axios chuẩn cho Client:

```javascript
import axios from 'axios';

const apiClient = axios.create({
  baseURL: 'http://localhost:8000/api/v1',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 10000,
});

// Request Interceptor: Tự động đính kèm Token
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response Interceptor: Bắt lỗi tập trung
apiClient.interceptors.response.use(
  (response) => {
    // Trả về trực tiếp phần payload data nếu cần
    return response.data;
  },
  (error) => {
    const res = error.response;
    if (!res) {
      console.error('Network Error or Server Down');
      return Promise.reject(error);
    }

    switch (res.status) {
      case 401:
        // Token hết hạn -> Xóa token và điều hướng về trang Login
        localStorage.removeItem('access_token');
        window.location.href = '/login';
        break;
      case 403:
        alert(res.data.message || 'Bạn không có quyền truy cập');
        break;
      case 409:
        alert(res.data.message || 'Dữ liệu bị xung đột hoặc bàn đã có người đặt');
        break;
      case 422:
        // Lỗi validation: ném đối tượng errors để Form hiển thị
        return Promise.reject(res.data.errors);
      case 429:
        alert('Thao tác quá nhanh, vui lòng thử lại sau giây lát!');
        break;
      default:
        console.error(res.data.message || 'Lỗi hệ thống');
    }

    return Promise.reject(res.data);
  }
);

export default apiClient;
```

---

*Tài liệu này là chuẩn mực giao tiếp bắt buộc cho mọi thành viên phát triển Backend và Frontend.*

