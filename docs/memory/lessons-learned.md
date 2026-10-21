# 🎓 BÀI HỌC KINH NGHIỆM & SỬA LỖI (LESSONS LEARNED)

Hệ thống lưu trữ các lỗi gặp phải trong quá trình phát triển dự án `restaurant-lavarel`.
Định dạng: `[Lỗi gặp phải] -> [Nguyên nhân] -> [Giải pháp fix]`

---

- **[Lệnh php và composer không nhận diện trong PowerShell (CommandNotFoundException)]** -> **[Laragon chưa được đưa vào biến môi trường PATH hệ thống của User]** -> **[Thêm đường dẫn `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64` và `C:\laragon\bin\composer` vào PATH khi thực thi lệnh CLI].**
- **[Composer không tải được package zip (The zip extension and unzip/7z commands are both missing)]** -> **[Dòng `;extension=zip` trong `php.ini` của Laragon đang bị comment]** -> **[Mở comment `extension=zip` trong `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.ini`].**
- **[Gọi API yêu cầu auth mà không truyền token bị lỗi 500 RouteNotFoundException và lộ stack trace]** -> **[Khi thiếu header Accept: application/json, Laravel mặc định redirect về route('login'). Do project thuần API không có route này nên ném lỗi 500 kèm debug trace]** -> **[Cấu hình `$middleware->redirectGuestsTo(fn () => null);`, tạo middleware `ForceJsonResponse` ép header `Accept: application/json` cho mọi route `api/*`, và bắt `AuthenticationException`, `RouteNotFoundException`, `Throwable` trong `withExceptions` trả về JSON 401/500 chuẩn không lộ field nhạy cảm].**
- **[Đơn POS/online không bao giờ chuyển `READY` dù mọi món đã xong]** -> **[`Eloquent\Builder::pluck()` áp dụng cast nên trả về enum `KitchenItemStatus`, so sánh `in_array(..., ['READY'], true)` với chuỗi luôn sai]** -> **[So sánh trực tiếp với case enum (`KitchenItemStatus::READY`) hoặc dùng query builder thuần khi cần chuỗi].**
- **[Quy tắc đặt bàn trước 30 phút/2 giờ và "hôm nay" tính sai 7 tiếng]** -> **[`app.timezone = UTC` trong khi khách nhập giờ Việt Nam dạng naive datetime]** -> **[Đặt `APP_TIMEZONE=Asia/Ho_Chi_Minh` làm mặc định trong `config/app.php`].**
- **[Test đổi tài khoản giữa các request trả 403 sai]** -> **[Guard Sanctum được cache trong cùng instance ứng dụng của test nên user đầu tiên bị tái sử dụng]** -> **[Mỗi test dùng một vai trò qua HTTP; chuẩn bị trạng thái của vai trò khác bằng model/factory].**

- **[Test feature gửi request thiếu header vẫn pass/không đúng kỳ vọng (ví dụ thiếu `Idempotency-Key` nhưng nhận 201)]** -> **[`withHeaders()` trong test Laravel được giữ lại cho mọi request tiếp theo của cùng test]** -> **[Gọi `$this->flushHeaders()` trước request cần kiểm tra thiếu header].**
- **[`assertJsonPath('...', 45000.0)` thất bại dù giá đúng]** -> **[JSON encode số thực tròn `45000.0` thành `45000`, decode ra `int`]** -> **[So sánh bằng closure `fn ($v) => (float) $v === (float) $expected`].**
- **[Regex trong file PHP/JS sinh bằng heredoc qua công cụ shell bị chèn ký tự backspace (`\b` → 0x08) hoặc mất dấu `\`]** -> **[Công cụ shell xử lý escape trong heredoc]** -> **[Viết file có `\` bằng công cụ ghi file trực tiếp, kiểm tra bằng `grep -c $'\x08'`].**

- **[Endpoint công khai “tham gia bàn” cấp quyền cho người giữ QR cũ]** -> **[Chỉ ràng buộc visit token theo phiên, còn `/join` vẫn tin QR in cố định]** -> **[QR chỉ để nhận diện; quyền vào phiên cần bí mật theo phiên (mã bàn) hoặc nhân viên duyệt; viết test cho cả trường hợp bỏ header].**
- **[Idempotency-Key giữ trong `useRef` vẫn gửi trùng sau reload]** -> **[Key mất khi trang tải lại trong khi giỏ được khôi phục]** -> **[Ghi key + payload vào storage bền vững trước khi gửi, chỉ xóa khi biết chắc kết quả; kiểm thử bằng Playwright `route.fetch()` rồi `route.abort()`].**
