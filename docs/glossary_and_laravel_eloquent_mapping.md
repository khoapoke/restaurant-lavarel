# TẦNG L2: TỪ ĐIỂN THUẬT NGỮ & ÁNH XẠ LARAVEL ELOQUENT (GLOSSARY & LARAVEL ORM MAPPING)

Tài liệu này định nghĩa bộ từ điển thuật ngữ chuẩn hóa và quy tắc ánh xạ từ **Cơ sở dữ liệu SQL Server (`QL_NHAHANG_CNJAVA_V2` - Tiếng Việt)** sang **Laravel Eloquent Models, Enums & API Resources (Tiếng Anh)**. Thiết kế này giúp lập trình viên tránh toàn bộ các lỗi kinh điển của Laravel khi làm việc với Database có sẵn (Legacy/Custom Schema).

---

## 1. Giải quyết 6 "Bẫy Lỗi" Kinh điển khi Map Laravel với SQL Server Hiện tại

Khi kết nối Laravel Eloquent vào cấu trúc 12 bảng của `QL_NHAHANG_CNJAVA_V2`, nếu dùng cấu hình mặc định của Laravel sẽ phát sinh lỗi ngay lập tức. Dưới đây là 6 quy tắc bắt buộc phải áp dụng trong các Model:

| STT | Lỗi Phát sinh trong Laravel (Root Cause) | Giải pháp Thiết kế Chuẩn trong Tài liệu này (Laravel Best Practice) |
| :--- | :--- | :--- |
| **1** | **Lỗi `Invalid column name 'created_at' / 'updated_at'`:**<br>Toàn bộ 12 bảng trong SQL Server hiện tại không có 2 cột `created_at` và `updated_at` mặc định của Laravel. | Bắt buộc khai báo `public $timestamps = false;` trong **tất cả 12 Eloquent Models**. Đối với bảng `GIO_HANG` (`ngay_tao`) và `DON_HANG` (`ngay_dat_hang`), sử dụng `useCurrent()` hoặc gán `now()` khi tạo mới. |
| **2** | **Lỗi `Illegal offset type` ở bảng Khóa chính phức hợp (`MON_AN_GIO_HANG`, `CHI_TIET_DON_HANG`):**<br>Laravel Eloquent **không hỗ trợ** mảng khóa chính `protected $primaryKey = ['ma_gio_hang', 'ma_mon_an']` khi gọi hàm `$model->save()` hoặc `$model->delete()`. | • Tuyệt đối không gọi `$cartItem->save()` trực tiếp trên Model thường.<br>• Sử dụng quan hệ **`belongsToMany(...)->withPivot(...)`** thông qua bảng trung gian (`Pivot`), thao tác bằng các hàm chuẩn của Laravel: `syncWithoutDetaching()`, `updateExistingPivot()`, và `detach()`. |
| **3** | **Lỗi `Invalid column name` khi dùng tên biến Tiếng Anh trong câu lệnh `where()`:**<br>Khác với Java Hibernate (`@Column`), trong Laravel Eloquent tên thuộc tính truy vấn SQL chính là tên cột thật (`ten_mon_an`). | • **Tầng Model:** Sử dụng **Laravel Accessors/Mutators (`Attribute::make`)** để đọc/ghi bằng tên Tiếng Anh (`$dish->dishName`) trong code PHP.<br>• **Tầng Response/Request:** Sử dụng **`JsonResource`** và **`FormRequest`** để giao tiếp API 100% bằng Tiếng Anh. |
| **4** | **Lỗi Xác thực `Auth::attempt()` thất bại với cột `mat_khau`:**<br>Laravel mặc định tìm cột tên là `password` trong bảng `users`. | Trong Model `Account` (`TAI_KHOAN` kế thừa `Authenticatable`), bắt buộc ghi đè hàm:<br>`public function getAuthPasswordName(): string { return 'mat_khau'; }`<br>`public function getAuthPassword(): string { return $this->mat_khau; }` |
| **5** | **Lỗi Không đăng nhập được dữ liệu mẫu (`mat_khau = '123'`):**<br>Dữ liệu mẫu trong file SQL đang lưu mật khẩu dạng rõ (`'123'`), trong khi `Hash::check()` của Laravel yêu cầu chuỗi băm `BCrypt` (`$2y$10$...`). | Trong `AuthService::login()`, kiểm tra bằng `Hash::check()`. Nếu mật khẩu trong DB chưa có tiền tố `$2y$` và khớp chuỗi rõ `'123'`, hệ thống tự động mã hóa lại bằng `Hash::make($plainPassword)` và cập nhật vào DB ngay lần đăng nhập đầu tiên. |
| **6** | **Lỗi Sai lệch tên Khóa ngoại trong Eloquent Relationship:**<br>Laravel mặc định tự suy diễn khóa ngoại theo dạng `<model>_id` (VD: `account_id`, `role_id`). | Mọi hàm quan hệ (`belongsTo`, `hasMany`, `hasOne`, `belongsToMany`) trong Model **bắt buộc phải truyền đủ cả `foreignKey` và `ownerKey`/`localKey`** (VD: `$this->belongsTo(Role::class, 'ma_vai_tro', 'ma_vai_tro')`). |

---

## 2. Bảng Ánh xạ Tổng thể Database sang Laravel Eloquent Models

Bảng dưới đây quy định cách ánh xạ chính xác 12 bảng trong `QL_NHAHANG_CNJAVA_V2` sang các lớp Model trong thư mục `app/Models/`:

| Vietnamese Table Name (Tên bảng SQL) | English Entity Name (Laravel Model / Pivot) | Description (Mô tả chức năng ngắn gọn) | Key Fields Mapping (`SQL Column` $\leftrightarrow$ `English Property / JSON Key`) & Eloquent Config |
| :--- | :--- | :--- | :--- |
| **`VAI_TRO`** | **`Role`**<br>(`App\Models\Role`) | Lưu trữ danh sách vai trò phân quyền (`ADMIN`, `STAFF`, `CUSTOMER`). | **Config:** `$table = 'VAI_TRO'`, `$primaryKey = 'ma_vai_tro'`, `$timestamps = false`<br>• `ma_vai_tro` (`INT PK`) $\leftrightarrow$ `roleId`<br>• `ten_vai_tro` (`NVARCHAR(50)`) $\leftrightarrow$ `roleName` |
| **`NGUOI_DUNG`** | **`UserProfile`**<br>(`App\Models\UserProfile`) | Lưu trữ hồ sơ cá nhân của người dùng (họ tên, ngày sinh, địa chỉ, SĐT, dị ứng). | **Config:** `$table = 'NGUOI_DUNG'`, `$primaryKey = 'ma_nguoi_dung'`, `$timestamps = false`<br>• `ma_nguoi_dung` (`INT PK`) $\leftrightarrow$ `userId`<br>• `ho_ten` (`NVARCHAR(100)`) $\leftrightarrow$ `fullName`<br>• `ngay_sinh` (`DATE`) $\leftrightarrow$ `birthDate`<br>• `dia_chi` (`NVARCHAR(255)`) $\leftrightarrow$ `address`<br>• `so_dien_thoai` (`NVARCHAR(20)`) $\leftrightarrow$ `phoneNumber` |
| **`TAI_KHOAN`** | **`Account`**<br>(`App\Models\Account` extends `Authenticatable`) | Thực thể xác thực đăng nhập, tích hợp `HasApiTokens` (Laravel Sanctum), quản lý trạng thái và vai trò. | **Config:** `$table = 'TAI_KHOAN'`, `$primaryKey = 'ma_tai_khoan'`, `$timestamps = false`<br>• `ma_tai_khoan` (`INT PK`) $\leftrightarrow$ `accountId`<br>• `ten_dang_nhap` (`VARCHAR(50) UNIQUE`) $\leftrightarrow$ `username`<br>• `mat_khau` (`VARCHAR(255)`) $\leftrightarrow$ `password` (Hidden in JSON)<br>• `email` (`VARCHAR(100)`) $\leftrightarrow$ `email`<br>• `trang_thai` (`NVARCHAR(100)`) $\leftrightarrow$ `status` (`AccountStatus` Enum)<br>• `ma_nguoi_dung` (`INT FK`) $\leftrightarrow$ `userId`<br>• `ma_vai_tro` (`INT FK`) $\leftrightarrow$ `roleId` |
| **`BAN_AN`** | **`DiningTable`**<br>(`App\Models\DiningTable`) | Quản lý danh sách bàn ăn vật lý tại nhà hàng (tên bàn, sức chứa, trạng thái, vị trí). | **Config:** `$table = 'BAN_AN'`, `$primaryKey = 'ma_ban'`, `$timestamps = false`<br>• `ma_ban` (`INT PK`) $\leftrightarrow$ `tableId`<br>• `ten_ban` (`NVARCHAR(50)`) $\leftrightarrow$ `tableName`<br>• `suc_chua` (`INT`) $\leftrightarrow$ `capacity`<br>• `trang_thai` (`NVARCHAR(50)`) $\leftrightarrow$ `status` (`TableStatus` Enum)<br>• `vi_tri` (`NVARCHAR(100)`) $\leftrightarrow$ `location` |
| **`DANH_MUC`** | **`Category`**<br>(`App\Models\Category`) | Phân loại nhóm món ăn trong thực đơn (Khai vị, Món chính, Đồ uống, Tráng miệng). | **Config:** `$table = 'DANH_MUC'`, `$primaryKey = 'ma_danh_muc'`, `$timestamps = false`<br>• `ma_danh_muc` (`INT PK`) $\leftrightarrow$ `categoryId`<br>• `ten_danh_muc` (`NVARCHAR(100)`) $\leftrightarrow$ `categoryName`<br>• `mo_ta` (`NVARCHAR(MAX)`) $\leftrightarrow$ `description` |
| **`MON_AN`** | **`Dish`**<br>(`App\Models\Dish`) | Thực thể món ăn, lưu giá bán hiện tại, mô tả, hình ảnh và thông tin nguyên liệu/dị ứng. | **Config:** `$table = 'MON_AN'`, `$primaryKey = 'ma_mon_an'`, `$timestamps = false`<br>• `ma_mon_an` (`INT PK`) $\leftrightarrow$ `dishId`<br>• `ten_mon_an` (`NVARCHAR(150)`) $\leftrightarrow$ `dishName`<br>• `gia_tien` (`DECIMAL(18,2)`) $\leftrightarrow$ `price`<br>• `mo_ta` (`NVARCHAR(MAX)`) $\leftrightarrow$ `description`<br>• `hinh_anh` (`VARCHAR(255)`) $\leftrightarrow$ `imageUrl`<br>• `ma_danh_muc` (`INT FK`) $\leftrightarrow$ `categoryId` |
| **`GIO_HANG`** | **`Cart`**<br>(`App\Models\Cart`) | Giỏ hàng cá nhân gắn cố định 1–1 với mỗi `Account`. | **Config:** `$table = 'GIO_HANG'`, `$primaryKey = 'ma_gio_hang'`, `$timestamps = false`<br>• `ma_gio_hang` (`INT PK`) $\leftrightarrow$ `cartId`<br>• `ngay_tao` (`DATETIME`) $\leftrightarrow$ `createdAt`<br>• `ma_tai_khoan` (`INT FK UNIQUE`) $\leftrightarrow$ `accountId` |
| **`MON_AN_GIO_HANG`** | **`CartItem`**<br>(`App\Models\CartItem` extends `Pivot`) | Bảng Pivot trung gian lưu danh sách món ăn và số lượng trong giỏ hàng. | **Config:** `$table = 'MON_AN_GIO_HANG'`, `$incrementing = false`, `$timestamps = false`<br>• `ma_gio_hang` (`INT PK, FK`) $\leftrightarrow$ `cartId`<br>• `ma_mon_an` (`INT PK, FK`) $\leftrightarrow$ `dishId`<br>• `so_luong` (`INT`) $\leftrightarrow$ `quantity` |
| **`DON_HANG`** | **`Order`**<br>(`App\Models\Order`) | Đơn đặt món chính thức; có thể do checkout online hoặc POS tạo. | **Config:** `$table = 'DON_HANG'`, `$primaryKey = 'ma_don_hang'`, `$timestamps = false`<br>• `ma_don_hang` (`INT PK`) $\leftrightarrow$ `orderId`<br>• `ngay_dat_hang` (`DATETIME`) $\leftrightarrow$ `orderDate`<br>• `tong_tien` (`DECIMAL(18,2)`) $\leftrightarrow$ `totalAmount`<br>• `trang_thai` (`NVARCHAR(50)`) $\leftrightarrow$ `status` (`OrderStatus`)<br>• `loai_phuc_vu` $\leftrightarrow$ `fulfillmentType` (`DELIVERY`, `PICKUP`, `DINE_IN`)<br>• `trang_thai_giao_nhan` $\leftrightarrow$ `fulfillmentStatus`<br>• `ten_nguoi_nhan`, `so_dien_thoai_nhan` $\leftrightarrow$ `recipientName`, `recipientPhone`<br>• `dia_chi_giao_hang` $\leftrightarrow$ `shippingAddress` (chỉ `DELIVERY`)<br>• `thoi_gian_nhan_du_kien` $\leftrightarrow$ `scheduledAt` (tùy chọn cho `PICKUP`)<br>• `phi_giao_hang` $\leftrightarrow$ `deliveryFee`<br>• `ma_tai_khoan` (`INT FK`) $\leftrightarrow$ `accountId` |
| **`CHI_TIET_DON_HANG`** | **`OrderItem`**<br>(`App\Models\OrderItem` extends `Pivot`) | Bảng Pivot lưu chi tiết món trong đơn hàng kèm đơn giá lịch sử (`don_gia`). | **Config:** `$table = 'CHI_TIET_DON_HANG'`, `$incrementing = false`, `$timestamps = false`<br>• `ma_don_hang` (`INT PK, FK`) $\leftrightarrow$ `orderId`<br>• `ma_mon_an` (`INT PK, FK`) $\leftrightarrow$ `dishId`<br>• `so_luong` (`INT`) $\leftrightarrow$ `quantity`<br>• `don_gia` (`DECIMAL(18,2)`) $\leftrightarrow$ `unitPrice` |
| **`THANH_TOAN`** | **`Payment`**<br>(`App\Models\Payment`) | Quản lý giao dịch thanh toán gắn 1–1 với `Order`. | **Config:** `$table = 'THANH_TOAN'`, `$primaryKey = 'ma_thanh_toan'`, `$timestamps = false`<br>• `ma_thanh_toan` (`INT PK`) $\leftrightarrow$ `paymentId`<br>• `so_tien` (`DECIMAL(18,2)`) $\leftrightarrow$ `amount`<br>• `phuong_thuc` (`NVARCHAR(50)`) $\leftrightarrow$ `paymentMethod` (`PaymentMethod` Enum)<br>• `trang_thai` (`NVARCHAR(50)`) $\leftrightarrow$ `status` (`PaymentStatus` Enum)<br>• `ma_don_hang` (`INT FK UNIQUE`) $\leftrightarrow$ `orderId` |
| **`DAT_BAN`** | **`Reservation`**<br>(`App\Models\Reservation`) | Quản lý phiếu đặt bàn trước của khách hàng theo khung giờ và bàn ăn. | **Config:** `$table = 'DAT_BAN'`, `$primaryKey = 'ma_dat_ban'`, `$timestamps = false`<br>• `ma_dat_ban` (`INT PK`) $\leftrightarrow$ `reservationId`<br>• `thoi_gian` (`DATETIME`) $\leftrightarrow$ `reservationTime`<br>• `so_luong_khach` (`INT`) $\leftrightarrow$ `guestCount`<br>• `trang_thai` (`NVARCHAR(50)`) $\leftrightarrow$ `status` (`ReservationStatus` Enum)<br>• `ghi_chu` (`NVARCHAR(MAX)`) $\leftrightarrow$ `note`<br>• `ma_tai_khoan` (`INT FK`) $\leftrightarrow$ `accountId`<br>• `ma_ban` (`INT FK`) $\leftrightarrow$ `tableId` |
| **`LUOT_KHACH_BAN`** | **`TableVisit`**<br>(`App\Models\TableVisit`) | Live Dining (ADR-020): một thiết bị khách tại bàn — yêu cầu nhận bàn hoặc khách đã tham gia phiên. | **Config:** `$table = 'LUOT_KHACH_BAN'`, `$primaryKey = 'ma_luot'`, `$timestamps = false`, `$hidden = ['token_hash']`<br>• `ma_ban` (FK `BAN_AN`), `ma_phien` (FK `PHIEN_PHUC_VU`, nullable)<br>• `token_hash` (`CHAR(64) UNIQUE`, SHA-256 của visit token)<br>• `ten_khach` $\leftrightarrow$ `guestName`, `so_khach` $\leftrightarrow$ `guestCount`<br>• `trang_thai` $\leftrightarrow$ `status` (`TableVisitStatus`: `PENDING`, `ACTIVE`, `REJECTED`, `CANCELLED`, `EXPIRED`)<br>• `ly_do_tu_choi` $\leftrightarrow$ `rejectReason`, `ma_nhan_vien_xu_ly`, `tao_luc`, `xu_ly_luc`, `truy_cap_luc` |
| **`YEU_CAU_PHUC_VU`** | **`ServiceRequest`**<br>(`App\Models\ServiceRequest`) | Live Dining: yêu cầu từ bàn tới nhân viên (gọi nhân viên, nước, dụng cụ, dọn bàn, thanh toán, khác). | **Config:** `$table = 'YEU_CAU_PHUC_VU'`, `$primaryKey = 'ma_yeu_cau'`, `$timestamps = false`<br>• `ma_phien`, `ma_ban`, `ma_luot` (FK)<br>• `loai` $\leftrightarrow$ `type` (`ServiceRequestType`), `ghi_chu` $\leftrightarrow$ `note`<br>• `phuong_thuc_mong_muon` $\leftrightarrow$ `preferredMethod` (`PaymentMethod`)<br>• `trang_thai` $\leftrightarrow$ `status` (`ServiceRequestStatus`: `NEW`, `ACKNOWLEDGED`, `COMPLETED`, `CANCELLED`)<br>• `tao_luc`, `nhan_luc`, `hoan_thanh_luc`, `huy_luc` $\leftrightarrow$ `createdAt`, `acknowledgedAt`, `completedAt`, `cancelledAt` |
| *Cột bổ sung (ADR-020)* | — | Mở rộng bảng hiện có cho Live Dining. | • `BAN_AN.ma_qr` (`VARCHAR(64) UNIQUE`, không mass-assign) $\leftrightarrow$ `qr.token`; `BAN_AN.ma_qr_tao_luc` $\leftrightarrow$ `qr.issuedAt`<br>• `PHIEU_BEP.nguon` $\leftrightarrow$ `source` (`KitchenTicketSource`: `POS`, `TABLE_QR`), `ma_luot`, `idempotency_key` (unique), `request_hash`<br>• `CHI_TIET_DON_HANG.bep_cap_nhat_luc` $\leftrightarrow$ `statusChangedAt`<br>• `PHIEN_PHUC_VU.ma_tham_gia` (`VARCHAR(8)`, ẩn, không mass-assign) $\leftrightarrow$ `joinCode`; `PHIEN_PHUC_VU.so_lan_sai_ma` $\leftrightarrow$ số lần nhập sai (`joinCodeLocked` khi ≥ 5) (ADR-022) |

---

## 3. Bảng Khai báo Quan hệ Eloquent Chuẩn Xác (Eloquent Relationship Matrix)

Để tránh lỗi `SQLSTATE[42S22]: Invalid column name` khi gọi Eager Loading (`with()`), toàn bộ các hàm Relationship trong Laravel Model **phải** khai báo đúng các tham số khóa dưới đây:

| Model Nguồn (Source Model) | Tên Hàm Quan hệ (Method Name) | Loại Quan hệ (Relation Type) | Cú pháp Khai báo Chính xác trong Laravel Model |
| :--- | :--- | :--- | :--- |
| **`Role`** | `accounts()` | `HasMany` | `return $this->hasMany(Account::class, 'ma_vai_tro', 'ma_vai_tro');` |
| **`UserProfile`** | `accounts()` | `HasMany` | `return $this->hasMany(Account::class, 'ma_nguoi_dung', 'ma_nguoi_dung');` |
| **`Account`** | `role()` | `BelongsTo` | `return $this->belongsTo(Role::class, 'ma_vai_tro', 'ma_vai_tro');` |
| **`Account`** | `userProfile()` | `BelongsTo` | `return $this->belongsTo(UserProfile::class, 'ma_nguoi_dung', 'ma_nguoi_dung');` |
| **`Account`** | `cart()` | `HasOne` | `return $this->hasOne(Cart::class, 'ma_tai_khoan', 'ma_tai_khoan');` |
| **`Account`** | `orders()` | `HasMany` | `return $this->hasMany(Order::class, 'ma_tai_khoan', 'ma_tai_khoan');` |
| **`Account`** | `reservations()` | `HasMany` | `return $this->hasMany(Reservation::class, 'ma_tai_khoan', 'ma_tai_khoan');` |
| **`Category`** | `dishes()` | `HasMany` | `return $this->hasMany(Dish::class, 'ma_danh_muc', 'ma_danh_muc');` |
| **`Dish`** | `category()` | `BelongsTo` | `return $this->belongsTo(Category::class, 'ma_danh_muc', 'ma_danh_muc');` |
| **`Cart`** | `account()` | `BelongsTo` | `return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');` |
| **`Cart`** | `dishes()` | `BelongsToMany` | `return $this->belongsToMany(Dish::class, 'MON_AN_GIO_HANG', 'ma_gio_hang', 'ma_mon_an', 'ma_gio_hang', 'ma_mon_an')->using(CartItem::class)->withPivot(['so_luong']);` |
| **`Cart`** | `items()` | `HasMany` | `return $this->hasMany(CartItem::class, 'ma_gio_hang', 'ma_gio_hang');` *(Chỉ dùng để đọc `SELECT`)* |
| **`Order`** | `account()` | `BelongsTo` | `return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');` |
| **`Order`** | `payment()` | `HasOne` | `return $this->hasOne(Payment::class, 'ma_don_hang', 'ma_don_hang');` |
| **`Order`** | `dishes()` | `BelongsToMany` | `return $this->belongsToMany(Dish::class, 'CHI_TIET_DON_HANG', 'ma_don_hang', 'ma_mon_an', 'ma_don_hang', 'ma_mon_an')->using(OrderItem::class)->withPivot(['so_luong', 'don_gia']);` |
| **`Order`** | `items()` | `HasMany` | `return $this->hasMany(OrderItem::class, 'ma_don_hang', 'ma_don_hang');` *(Chỉ dùng để đọc `SELECT`)* |
| **`Payment`** | `order()` | `BelongsTo` | `return $this->belongsTo(Order::class, 'ma_don_hang', 'ma_don_hang');` |
| **`DiningTable`** | `reservations()` | `HasMany` | `return $this->hasMany(Reservation::class, 'ma_ban', 'ma_ban');` |
| **`Reservation`** | `account()` | `BelongsTo` | `return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');` |
| **`Reservation`** | `diningTable()` | `BelongsTo` | `return $this->belongsTo(DiningTable::class, 'ma_ban', 'ma_ban');` |

---

## 4. Thiết kế PHP 8.1+ Backed Enums & Ánh xạ Trạng thái Tiếng Việt (`app/Enums/`)

Trong PHP 8.1+ và Laravel 10/11, Eloquent hỗ trợ ép kiểu tự động (`$casts`) sang **String-Backed Enum**. Cách này cho phép bạn viết code hoàn toàn bằng hằng số Tiếng Anh (`OrderStatus::PREPARING`), trong khi Laravel tự động lưu chuỗi Tiếng Việt (`N'Đang chuẩn bị'`) xuống SQL Server và trả về mã Tiếng Anh (`"PREPARING"`) ra JSON API:

| Tên Enum Class (`app/Enums/`) | Hằng số Tiếng Anh (Case Name) | Giá trị Lưu trong Database (`Backed String Value`) | Mã trả ra JSON API (`name`) |
| :--- | :--- | :--- | :--- |
| **`RoleType`** | `ADMIN` | `'Quản trị viên'` (`ma_vai_tro = 1`) | `"ADMIN"` |
| | `STAFF` | `'Nhân viên'` (`ma_vai_tro = 2`) | `"STAFF"` |
| | `CUSTOMER` | `'Khách hàng'` (`ma_vai_tro = 3`) | `"CUSTOMER"` |
| **`AccountStatus`** | `ACTIVE` | `'Hoạt động'` | `"ACTIVE"` |
| | `LOCKED` | `'Bị khóa'` | `"LOCKED"` |
| **`TableStatus`** | `AVAILABLE` | `'Trống'` | `"AVAILABLE"` |
| | `RESERVED` | `'Đã đặt'` | `"RESERVED"` |
| | `OCCUPIED` | `'Đang phục vụ'` | `"OCCUPIED"` |
| | `MAINTENANCE` | `'Bảo trì'` | `"MAINTENANCE"` |
| **`OrderStatus`** | `PENDING_CONFIRMATION` | `'Chờ xác nhận'` | `"PENDING_CONFIRMATION"` |
| | `CONFIRMED` | `'Đã xác nhận'` | `"CONFIRMED"` |
| | `PREPARING` | `'Đang chuẩn bị'` | `"PREPARING"` |
| | `READY` | `'Sẵn sàng'` | `"READY"` |
| | `COMPLETED` | `'Đã giao'` | `"COMPLETED"` |
| | `CANCELLED` | `'Đã hủy'` | `"CANCELLED"` |
| **`PaymentMethod`** | `CASH` | `'Tiền mặt'` | `"CASH"` |
| | `BANK_TRANSFER_QR` | `'Chuyển khoản'` | `"BANK_TRANSFER_QR"` |
| **`PaymentStatus`** | `UNPAID` | `'Chưa thanh toán'` | `"UNPAID"` |
| | `PAID` | `'Đã thanh toán'` | `"PAID"` |
| | `FAILED` | `'Thất bại'` | `"FAILED"` |
| | `REFUNDED` | `'Đã hoàn tiền'` | `"REFUNDED"` |
| **`ReservationStatus`**| `PENDING` | `'Chờ xử lý'` | `"PENDING"` |
| | `CONFIRMED` | `'Đã xác nhận'` | `"CONFIRMED"` |
| | `REJECTED` | `'Từ chối'` | `"REJECTED"` |
| | `CANCELLED` | `'Đã hủy'` | `"CANCELLED"` |
| | `COMPLETED` | `'Hoàn thành'` | `"COMPLETED"` |

---

## 5. Mẫu Cấu hình Model Chuẩn trong Laravel (Tránh Lỗi Map & Bảng Trung Gian)

### 5.1. Mẫu Model `Account.php` (Xử lý Custom Table, Custom Password & Sanctum)
```php
<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

class Account extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'TAI_KHOAN';
    protected $primaryKey = 'ma_tai_khoan';
    public $timestamps = false;

    protected $fillable = [
        'ten_dang_nhap',
        'mat_khau',
        'email',
        'trang_thai',
        'ma_nguoi_dung',
        'ma_vai_tro',
    ];

    protected $hidden = [
        'mat_khau',
    ];

    protected $casts = [
        'trang_thai' => AccountStatus::class,
    ];

    // Bắt buộc ghi đè để Laravel Auth/Sanctum nhận diện cột mật khẩu 'mat_khau'
    public function getAuthPasswordName(): string
    {
        return 'mat_khau';
    }

    public function getAuthPassword(): string
    {
        return $this->mat_khau;
    }

    // Accessors/Mutators giúp truy cập bằng tên Tiếng Anh trong code PHP
    protected function accountId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_tai_khoan);
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ten_dang_nhap,
            set: fn (string $value) => ['ten_dang_nhap' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trang_thai,
            set: fn (AccountStatus|string $value) => ['trang_thai' => $value]
        );
    }

    // Relationships
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'ma_vai_tro', 'ma_vai_tro');
    }

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'ma_nguoi_dung', 'ma_nguoi_dung');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }
}
```

### 5.2. Giải pháp Migration Mở rộng các Trường Nghiệp vụ Nâng cao (Không Phá vỡ Schema Cũ)
Để hỗ trợ đầy đủ các tính năng trong file `.txt` (như: *dị ứng, chế độ ăn, xóa mềm, ẩn/hiện, nguyên liệu, ghi chú món, mã giao dịch QR*) mà **không làm hỏng script SQL gốc**, bạn chỉ cần tạo 1 file Laravel Migration bổ sung các cột `nullable` có giá trị `default` an toàn:

| Bảng SQL (`Table`) | Các cột khuyến nghị thêm qua Laravel Migration (`Schema::table`) | Kiểu dữ liệu Laravel Blueprint & Ánh xạ `$casts` trong Model |
| :--- | :--- | :--- |
| **`NGUOI_DUNG`** | `di_ung`, `che_do_an`, `so_dia_chi` | • `$table->json('di_ung')->nullable();` $\rightarrow$ `'di_ung' => 'array'` (`allergies`)<br>• `$table->json('che_do_an')->nullable();` $\rightarrow$ `'che_do_an' => 'array'` (`dietaryPreferences`)<br>• `$table->json('so_dia_chi')->nullable();` $\rightarrow$ `'so_dia_chi' => 'array'` (`savedAddresses`) |
| **`DANH_MUC`** | `thu_tu_hien_thi`, `hien_thi`, `da_xoa` | • `$table->integer('thu_tu_hien_thi')->default(0);` (`displayOrder`)<br>• `$table->boolean('hien_thi')->default(true);` (`isVisible`)<br>• `$table->boolean('da_xoa')->default(false);` (`isDeleted`) |
| **`MON_AN`** | `nguyen_lieu`, `chat_di_ung`, `the_thoi_tiet`, `con_ban`, `hien_thi`, `da_xoa` | • `$table->json('nguyen_lieu')->nullable();` $\rightarrow$ `'array'` (`ingredients`)<br>• `$table->json('chat_di_ung')->nullable();` $\rightarrow$ `'array'` (`allergens`)<br>• `$table->json('the_thoi_tiet')->nullable();` $\rightarrow$ `'array'` (`weatherTags`)<br>• `$table->boolean('con_ban')->default(true);` (`isAvailable`)<br>• `$table->boolean('hien_thi')->default(true);` (`isVisible`)<br>• `$table->boolean('da_xoa')->default(false);` (`isDeleted`) |
| **`THANH_TOAN`** | `ma_giao_dich`, `log_callback`, `ly_do_hoan_tien` | • `$table->string('ma_giao_dich', 100)->nullable();` (`transactionCode`)<br>• `$table->text('log_callback')->nullable();` (`callbackLog`)<br>• `$table->string('ly_do_hoan_tien', 500)->nullable();` (`refundReason`) |

> **Mẹo dự phòng (Zero-Alter Fallback):** Nếu giảng viên yêu cầu giữ nguyên 100% câu lệnh `CREATE TABLE` trong file `.sql` và không chạy thêm `ALTER TABLE`, bạn có thể dùng `Attribute::make()` của Laravel để parse/serialize JSON các trường mở rộng (`ingredients`, `allergens`, `weatherTags`, `isAvailable`) ngay bên trong cột `MON_AN.mo_ta` (`NVARCHAR(MAX)`).
