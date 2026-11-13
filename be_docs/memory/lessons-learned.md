# 🎓 BÀI HỌC KINH NGHIỆM & SỬA LỖI (LESSONS LEARNED)

Hệ thống lưu trữ các lỗi gặp phải trong quá trình phát triển dự án `restaurant-lavarel`.
Định dạng: `[Lỗi gặp phải] -> [Nguyên nhân] -> [Giải pháp fix]`

---

- **[Lệnh php và composer không nhận diện trong PowerShell (CommandNotFoundException)]** -> **[Laragon chưa được đưa vào biến môi trường PATH hệ thống của User]** -> **[Thêm đường dẫn `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64` và `C:\laragon\bin\composer` vào PATH khi thực thi lệnh CLI].**
- **[Composer không tải được package zip (The zip extension and unzip/7z commands are both missing)]** -> **[Dòng `;extension=zip` trong `php.ini` của Laragon đang bị comment]** -> **[Mở comment `extension=zip` trong `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.ini`].**
- **[Gọi API yêu cầu auth mà không truyền token bị lỗi 500 RouteNotFoundException và lộ stack trace]** -> **[Khi thiếu header Accept: application/json, Laravel mặc định redirect về route('login'). Do project thuần API không có route này nên ném lỗi 500 kèm debug trace]** -> **[Cấu hình `$middleware->redirectGuestsTo(fn () => null);`, tạo middleware `ForceJsonResponse` ép header `Accept: application/json` cho mọi route `api/*`, và bắt `AuthenticationException`, `RouteNotFoundException`, `Throwable` trong `withExceptions` trả về JSON 401/500 chuẩn không lộ field nhạy cảm].**
- **[Sanctum tokenable_type không nhận diện sau khi di chuyển Model Account sang Module]** -> **[Cột `tokenable_type` trong bảng `personal_access_tokens` lưu class string cũ (`App\Models\Account`), khi đổi namespace sang `App\Modules\Auth\Models\Account` thì Sanctum không tìm thấy model tương ứng]** -> **[Khai báo `Relation::morphMap(['App\Models\Account' => \App\Modules\Auth\Models\Account::class])` trong `AppServiceProvider::boot()` và tạo lớp kế thừa rỗng tại `app/Models/Account.php` để giữ 100% tương thích ngược].**
- **[CheckRole middleware trả về 403 Forbidden trên cơ sở dữ liệu test fresh]** -> **[CheckRole ban đầu kiểm tra cứng ID (1: Admin, 2: Staff, 3: Customer), khi test chạy RefreshDatabase và tạo động vai trò thì `ma_vai_tro` được cấp phát bắt đầu từ 1]** -> **[Bổ sung cơ chế đối soát động theo tên chuỗi vai trò (`$user->role->ten_vai_tro`) và tên constant Backed Enum `RoleType` bên cạnh kiểm tra ID tĩnh].**
- **[Swarm Workers bị dừng đột ngột với mã lỗi RESOURCE_EXHAUSTED 429]** -> **[Model được chỉ định chạy swarm vượt quá giới hạn rate limit/quota cá nhân khi khởi chạy đồng thời nhiều subagents]** -> **[Sử dụng lệnh `swarm{action:"models"}` để tra cứu model khả dụng còn dung lượng quota cao (ví dụ: `gemini-3.8-flash-high`), sau đó dùng `swarm{action:"model", name: <worker>, model: <new_model>}` để chuyển đổi toàn bộ worker sang model mới mà không làm mất lịch sử hội thoại hay workspace].**


