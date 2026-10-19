# 🎓 BÀI HỌC KINH NGHIỆM & SỬA LỖI (LESSONS LEARNED)

Hệ thống lưu trữ các lỗi gặp phải trong quá trình phát triển dự án `restaurant-lavarel`.
Định dạng: `[Lỗi gặp phải] -> [Nguyên nhân] -> [Giải pháp fix]`

---

- **[Lệnh php và composer không nhận diện trong PowerShell (CommandNotFoundException)]** -> **[Laragon chưa được đưa vào biến môi trường PATH hệ thống của User]** -> **[Thêm đường dẫn `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64` và `C:\laragon\bin\composer` vào PATH khi thực thi lệnh CLI].**
- **[Composer không tải được package zip (The zip extension and unzip/7z commands are both missing)]** -> **[Dòng `;extension=zip` trong `php.ini` của Laragon đang bị comment]** -> **[Mở comment `extension=zip` trong `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.ini`].**

