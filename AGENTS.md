<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

---

## 📚 TÀI LIỆU ĐẶC TẢ HỆ THỐNG (SYSTEM SPECIFICATIONS)

Trước khi thực hiện bất kỳ tác vụ nào liên quan đến Database, Logic nghiệp vụ hay API, Agent BẮT BUỘC phải tham khảo và tuân thủ các tài liệu đặc tả sau:
1. [`docs/glossary_and_laravel_eloquent_mapping.md`](docs/glossary_and_laravel_eloquent_mapping.md): Định nghĩa 12 Model Eloquent, bảng quan hệ (Relationship Matrix), Backed Enums, cách xử lý 6 bẫy lỗi mapping SQL Server.
2. [`docs/business_logic_and_validation_rules.md`](docs/business_logic_and_validation_rules.md): Quy trình thuật toán chuyên sâu (AuthService, CartService, OrderService, ReservationService, RecommendationService).
3. [`docs/laravel_restful_api_specifications.md`](docs/laravel_restful_api_specifications.md): Danh sách toàn bộ Endpoint `/api/v1/...` (Shared Auth, Customer, Admin) kèm Request/Response JSON mẫu và mã HTTP status.


## 🧠 QUY TẮC TỰ ĐỘNG GHI NHỚ (AUTO-MEMORY SYSTEM)

Bạn có quyền và có nghĩa vụ cập nhật hệ thống bộ nhớ của dự án tại thư mục `docs/memory/` theo quy tắc sau:
1. **Sau khi fix một bug khó hoặc lỗi môi trường:** Hãy tự động thêm một dòng vào file `docs/memory/lessons-learned.md` theo cấu trúc: [Lỗi gặp phải] -> [Nguyên nhân] -> [Giải pháp fix].
2. **Sau khi thống nhất một logic nghiệp vụ mới với tôi:** Hãy cập nhật vào file `docs/memory/architectural-decisions.md` để lưu lại quyết định đó.
3. **Trước khi bắt đầu bất kỳ task code nào:** Bạn PHẢI đọc thư mục `docs/memory/` này để đảm bảo không lặp lại lỗi cũ và đi đúng hướng kiến trúc đã chọn.

