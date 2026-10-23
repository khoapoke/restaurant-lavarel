# Kiểm toán ngoại lệ & ràng buộc nghiệp vụ — Mộc Dining

> **Cập nhật 2026-10-21:** các case đã được xử lý trong milestone "ngoại lệ nghiệp vụ". Trạng thái từng case,
> thay đổi, test bằng chứng và giới hạn còn lại: `docs/reviews/business-edge-case-mapping.md`. Nội dung dưới đây giữ
> nguyên như lúc kiểm toán để đối chiếu; mục 10 "ĐỀ XUẤT, CHƯA ÁP DỤNG" nay đã được chốt thành chính sách tại
> `docs/operations/exception-policies.md`.

> **Loại tài liệu:** rà soát, tái hiện và đề xuất. **Chưa thay đổi nghiệp vụ hay code ứng dụng.**
> **Baseline:** backend `b35f655`, frontend `073310c` (working tree sạch khi bắt đầu).
> **Ngày:** 2026-10-21.

## 0. Phạm vi, phương pháp và cách đọc

- Đọc `AGENTS.md`, `CLAUDE.md`, `docs/business_logic_and_validation_rules.md`, `docs/laravel_restful_api_specifications.md`, ADR-013 → ADR-022, mã nguồn service/controller/request/resource/route backend, các trang POS/KDS/admin/khách ở frontend và toàn bộ `tests/Feature`.
- Mỗi kết luận quan trọng được **tái hiện bằng test cô lập** chạy trên SQLite in-memory (không chạm DB dev, không `migrate:fresh`):
  - File: `docs/reviews/BusinessEdgeCaseAuditReproTest.php` (ngoài thư mục `tests/` nên không chạy trong suite chính).
  - Chạy: `vendor/bin/phpunit docs/reviews/BusinessEdgeCaseAuditReproTest.php` → **22 test / 105 assertion pass**.
  - Mỗi test khẳng định **hành vi hiện tại**: test pass nghĩa là lỗi/khoảng trống **vẫn còn**. Khi sửa một case, test tương ứng sẽ fail — lúc đó thay bằng test nghiệm thu ở cột "Test nghiệm thu".
- Ký hiệu bằng chứng: `Customer/ReservationService.php:197-214` = `app/Services/Customer/ReservationService.php` dòng 197–214. `Repro: test_xxx` = test trong file tái hiện.

**Phân loại:**

| Mã | Ý nghĩa |
|---|---|
| **A** | Đã xử lý đầy đủ (có kiểm tra ở API, có đường thao tác, có test). |
| **B** | Có chặn nhưng chưa có cách xử lý tiếp cho nhân viên. |
| **C** | Chưa có nghiệp vụ. |
| **D** | Có lỗi hoặc ràng buộc sai. |
| **E** | Chưa đủ bằng chứng (thường là đồng thời thật trên DB production, chưa kiểm được trên SQLite). |

**Ưu tiên:** P1 sai tiền / mất đơn / lộ dữ liệu · P2 làm nhà hàng không phục vụ tiếp được · P3 đặt bàn, nhận khách, xử lý món thực tế · P4 hiếm gặp, xử lý thủ công được.

> Lưu ý chung về đồng thời: các thao tác quan trọng dùng `DB::transaction` + `lockForUpdate()`. Trên MySQL/SQL Server đây là khóa dòng thật; trên SQLite dev chỉ có khóa toàn DB và `php artisan serve` chạy đơn luồng, nên **không có test nào chứng minh hai request song song thật sự an toàn**. Những case phụ thuộc điều này được ghi E khi không có bằng chứng khác.

---

## 1. Tóm tắt

| Nhóm | A | B | C | D | E |
|---|---|---|---|---|---|
| Đặt bàn & nhận khách (RSV) | 2 | 4 | 7 | 6 | 0 |
| Phiên phục vụ & bàn (SES) | 4 | 2 | 4 | 0 | 1 |
| QR tại bàn (QR) | 8 | 4 | 0 | 0 | 0 |
| POS & bếp (KDS) | 3 | 2 | 6 | 1 | 0 |
| Giao hàng & tự lấy (DLV) | 0 | 4 | 4 | 2 | 0 |
| Hóa đơn, thanh toán, hoàn tiền (PAY) | 6 | 1 | 3 | 7 | 0 |
| Thực đơn, quyền, lịch sử (MNU/AUTH/AUD) | 2 | 1 | 3 | 2 | 0 |

> Case có hai nhãn (ví dụ "A/B", "A trước / C sau") được đếm theo nhãn **xấu hơn**; riêng nhãn "A/E" được đếm là A (phần đồng thời thật ghi chú ở mục 0). Tổng: 89 case.

**Lỗi có bằng chứng (D) quan trọng nhất:** PAY-03, PAY-06, PAY-07, PAY-11, PAY-16 (tiền về không được ghi nhận/đếm sai); DLV-04 + DLV-10 (đơn kẹt vĩnh viễn ở "đang giao"); KDS-03 (phiên không thể đóng); RSV-14, RSV-17 (không nhận được khách đặt trước); MNU-01 (ẩn danh mục nhưng món vẫn bán).

---

## 2. Ba khoảng thời gian đang dùng chung con số 119 phút

Codex lưu ý đúng: `TableStatusSynchronizer::SLOT_MINUTES = 119` (`Shared/TableStatusSynchronizer.php:20`) và hằng `119` viết cứng (`Customer/ReservationService.php:38-39, 203-204`, `Admin/ReservationService.php:90-91`) đang được dùng cho **bốn** mục đích khác nhau:

| Mục đích | Nơi dùng | Ý nghĩa thực tế hiện nay |
|---|---|---|
| Thời lượng một lượt dùng bàn (xung đột đặt bàn) | `Customer/ReservationService.php:197-214`, `Admin/ReservationService.php:85-96` | Hai lượt đặt cùng bàn phải cách nhau ≥ 120 phút. |
| Thời gian giữ bàn khi khách trễ | `TableStatusSynchronizer.php:44, 56` | Bàn bị giữ tới **119 phút sau giờ hẹn**. |
| Khoảng cho nhận bàn sớm / chặn khách vãng lai trước giờ hẹn | `TableStatusSynchronizer.php:56`, `Staff/PosTableResource` (`isWithinSeatingWindow`) | Khách vãng lai bị chặn từ **119 phút trước giờ hẹn**; nút "Nhận khách đặt trước" hiện trong ±119 phút. |
| Hạn duyệt lượt đặt đã qua | `Admin/ReservationService.php:77` | Admin vẫn duyệt được lượt đặt đã bắt đầu 119 phút trước. |

Kết quả: một lượt đặt 19:00 giữ bàn từ 17:01 đến 20:59 — tức 4 tiếng — dù khách chỉ ngồi 2 tiếng và có thể không đến. Đề xuất tách thành 4 tham số độc lập ở mục 10.

**Ví dụ cụ thể (Repro: `test_rsv_late_guest_keeps_the_table_locked_and_nothing_expires`):** khách đặt 19:00, lúc 19:21 chưa đến.
- Lượt đặt vẫn `CONFIRMED`; không có trạng thái "trễ" hay `NO_SHOW` (`ReservationStatus` chỉ có PENDING/CONFIRMED/REJECTED/CANCELLED/COMPLETED).
- Bàn vẫn bị giữ: mở phiên cho khách vãng lai trả 409 *"…is held for reservation #… at 19:00"* tới 20:58.
- Nhân viên (role STAFF) **không có thao tác nào**: route đổi trạng thái lượt đặt là `role:ADMIN` (`routes/api.php:140`), test xác nhận 403. Chỉ admin mới bấm được "Hủy lịch" hoặc "Hoàn thành".
- Khách đến 19:35: nếu admin chưa hủy, nhân viên bấm "Nhận khách đặt trước" bình thường (trong ±119 phút). Nếu admin đã hủy và bàn đã nhường, khách đó chỉ được xử lý như khách vãng lai, không có lịch sử "đến trễ".
- 23:59 lượt đặt vẫn `CONFIRMED` mãi mãi; báo cáo coi đây là lượt đặt "đã xác nhận".

---

## 3. Đặt bàn và nhận khách (RSV)

### RSV-01 · Đặt trùng bàn/giờ, hai người đặt cùng lúc — **A** (P3)
- **Tình huống:** hai khách cùng chọn Bàn T02 lúc 19:00 ngày mai.
- **Hiện tại:** người thứ hai nhận 409 *"This table already has an overlapping reservation"*. Lượt `PENDING` cũng chặn, nên không thể đặt chồng trước khi admin duyệt.
- **Bằng chứng:** `Customer/ReservationService.php:58-80` (khóa `BAN_AN` bằng `lockForUpdate`, kiểm tra `assertNoOverlap` dòng 197-214); test `CustomerReservationApiTest`.
- **Ảnh hưởng/đề xuất:** đủ cho MVP. Đồng thời thật phụ thuộc khóa dòng của DB production (xem lưu ý mục 0).
- **Test nghiệm thu nên có:** test đồng thời trên MySQL/SQL Server khi có môi trường CI tương ứng.

### RSV-02 · Một khách gửi nhiều yêu cầu để giữ nhiều bàn — **C** (P3)
- **Tình huống:** kh1 gửi 3 yêu cầu cho 3 bàn khác nhau cùng 19:00 ngày mai.
- **Hiện tại:** cả 3 được tạo `PENDING`, và mỗi yêu cầu `PENDING` đã chặn người khác đặt bàn đó.
- **Bằng chứng:** Repro `test_rsv_one_customer_can_hold_many_tables_and_book_outside_opening_hours`; `CreateReservationRequest` không giới hạn số lượt; `Customer/ReservationService.php:58-80` không đếm lượt đang hoạt động của khách.
- **Ảnh hưởng:** một tài khoản (hoặc bot) có thể khóa cả sàn trong giờ cao điểm.
- **Đề xuất:** giới hạn số lượt `PENDING/CONFIRMED` trùng khung giờ trên mỗi tài khoản; cảnh báo admin khi một khách có nhiều lượt cùng giờ.
- **Cần chốt:** mỗi khách tối đa bao nhiêu lượt cùng khung giờ (đề xuất 1) và bao nhiêu lượt đang hoạt động trong tương lai (đề xuất 3).
- **Test nghiệm thu:** lượt thứ 2 cùng khung giờ trả 409; lượt khác khung vẫn tạo được.

### RSV-03 · Yêu cầu PENDING bị bỏ quên hoặc đã qua giờ — **C** (P3)
- **Tình huống:** khách đặt 18:00, admin không duyệt; 20:30 lượt vẫn `PENDING`.
- **Hiện tại:** admin không duyệt được nữa (409 *"This reservation time has already passed"*), khách không hủy được (hết hạn 60 phút), lượt **mãi ở `PENDING`**. Dashboard chỉ đếm `PENDING` trong tương lai nên lượt này biến khỏi chỉ số "cần xử lý". Nó vẫn có thể chặn các lượt đặt khác trong ±119 phút.
- **Bằng chứng:** Repro `test_rsv_forgotten_pending_request_stays_pending_forever`; `Admin/ReservationService.php:76-79`; `Admin/ReportService.php` (`reservationsToConfirm` lọc `thoi_gian >= now`).
- **Ảnh hưởng:** khách không biết lượt bị bỏ; dữ liệu treo; có thể chặn đặt bàn.
- **Đề xuất:** tự chuyển `PENDING` chưa duyệt sang `EXPIRED` (hoặc `REJECTED` kèm lý do hệ thống) trước giờ hẹn X phút; báo cho khách.
- **Cần chốt:** hạn duyệt (đề xuất: trễ nhất 30 phút trước giờ hẹn) và có thông báo cho khách hay không.
- **Test nghiệm thu:** lượt `PENDING` qua hạn → `EXPIRED`, không còn chặn bàn, hiện ở mục lịch sử của khách.

### RSV-04 · Khách trễ 10/20/30 phút — **B** (P3)
- **Hiện tại:** xem ví dụ mục 2. Bàn giữ tới 119 phút sau giờ hẹn; không có cảnh báo "khách trễ" trên POS; nhân viên không có thao tác.
- **Bằng chứng:** Repro `test_rsv_late_guest_…`; `TableStatusSynchronizer.php:51-60`; `Staff/TableCard.jsx:16-17` (chỉ có nút nhận khách).
- **Ảnh hưởng:** bàn trống mất doanh thu giờ cao điểm, hoặc nhân viên phải nhờ admin.
- **Đề xuất:** POS hiển thị "trễ N phút" sau giờ hẹn; quá *thời gian giữ chỗ* thì bàn được phép nhận khách vãng lai, còn lượt đặt chuyển "chờ quyết định" để nhân viên đánh dấu `NO_SHOW` hoặc gia hạn.
- **Cần chốt:** thời gian giữ chỗ (đề xuất 15 phút, gia hạn tối đa 30 phút nếu khách báo trước).
- **Test nghiệm thu:** 19:16 khách vãng lai mở được bàn (kèm cảnh báo lượt đặt trễ); 19:10 vẫn bị chặn.

### RSV-05 · Khách báo trước sẽ đến trễ — **C** (P3)
- **Hiện tại:** trong vòng 60 phút trước giờ hẹn khách không sửa được (`Customer/ReservationService.php:156-165`); admin không có API sửa giờ/bàn của lượt đặt (chỉ đổi trạng thái — `Admin/ReservationService.php:56-109`); nhân viên không có quyền nào.
- **Đề xuất:** thao tác "Khách báo trễ +N phút" cho nhân viên/admin, ghi lý do, kéo dài thời gian giữ chỗ.
- **Cần chốt:** mức gia hạn tối đa; có kiểm tra xung đột với lượt kế tiếp không (đề xuất có).
- **Test nghiệm thu:** gia hạn 20 phút → khách vãng lai bị chặn tới 19:35; gia hạn xung đột lượt sau → 409.

### RSV-06 · Khách không đến (no-show) — **C** (P3)
- **Hiện tại:** không có `NO_SHOW`. Admin chỉ có "Hủy lịch" (CANCELLED) hoặc "Hoàn thành" (COMPLETED). "Hoàn thành" được phép dù chưa từng nhận bàn, nên dữ liệu khách không đến có thể bị ghi thành "hoàn thành".
- **Bằng chứng:** `ReservationStatus::canTransitionTo` (CONFIRMED → COMPLETED/CANCELLED); `Admin/ReservationService.php:69-74` chỉ chặn khi đang có phiên; Repro `test_rsv_admin_can_complete_an_unseated_booking_and_admin_notes_are_customer_visible`; `AdminReservationsPage.jsx:129-130`.
- **Ảnh hưởng:** không thống kê được tỷ lệ no-show, không áp dụng được chính sách cọc/hạn chế khách hay bỏ.
- **Đề xuất:** thêm `NO_SHOW` (nhân viên được đánh dấu sau thời gian giữ chỗ); chặn `COMPLETED` nếu chưa có phiên phục vụ gắn với lượt đặt.
- **Cần chốt:** có tự đánh dấu no-show sau X phút không; khách no-show N lần thì có hạn chế đặt không.
- **Test nghiệm thu:** CONFIRMED chưa nhận bàn → COMPLETED trả 409; → NO_SHOW được (STAFF/ADMIN), bàn được giải phóng.

### RSV-07 · Khách đến rất sớm — **D** (P3)
- **Tình huống:** lượt đặt 21:00, khách đến 15:00.
- **Hiện tại:** UI chỉ hiện nút "Nhận khách đặt trước" trong ±119 phút. Nhưng API `POST /staff/pos/sessions` với `reservationId` **không kiểm tra khung giờ**, chỉ kiểm tra cùng ngày — mở phiên lúc 15:00 thành công.
- **Bằng chứng:** Repro `test_rsv_reservation_can_be_seated_any_time_the_same_day_through_the_api`; `Staff/PosSessionService.php:231-249` (`isSameDay`, dòng 243).
- **Ảnh hưởng:** bàn có thể bị chiếm nhiều giờ trước lượt đặt kế tiếp; quy tắc phụ thuộc UI. Kết hợp với RSV-15, lượt đặt còn có thể bị khách hủy trong lúc đang ngồi.
- **Đề xuất:** kiểm tra cửa sổ nhận bàn sớm ở API; nhận sớm hơn cửa sổ thì xử lý như khách vãng lai, hoặc phải có xác nhận của quản lý.
- **Cần chốt:** cửa sổ nhận sớm (đề xuất 30 phút).
- **Test nghiệm thu:** nhận bàn sớm hơn cửa sổ → 409; trong cửa sổ → OK.

### RSV-08 · Khách đến sau khi bàn đã nhường cho người khác — **C** (P3)
- **Hiện tại:** nếu admin đã hủy lượt → chỉ còn đường khách vãng lai, mất liên kết với lượt đặt. Nếu chưa hủy nhưng bàn đang có người → xem RSV-14 (không nhận được sang bàn khác).
- **Đề xuất:** cho phép "nhận khách đặt trước sang bàn khác" (RSV-14) và giữ liên kết lượt đặt khi chuyển từ `NO_SHOW` về nhận bàn trong ngày.
- **Cần chốt:** khách đến sau khi bị đánh dấu no-show có được khôi phục lượt đặt không.

### RSV-09 · Số khách thực tế ít/nhiều hơn số đã đặt (trong sức chứa) — **A** (P4)
- **Hiện tại:** modal mở phiên cho nhập số khách thực tế; API chỉ kiểm tra không vượt sức chứa bàn (`PosSessionService.php:162-165`). Lượt đặt giữ số khách ban đầu (để đối chiếu).
- **Ghi chú:** số khách không đổi được sau khi mở phiên (SES-04).

### RSV-10 · Số khách vượt sức chứa, cần đổi hoặc ghép bàn — **B** (P3)
- **Hiện tại:** API mở phiên nhận nhiều `tableIds` (ghép bàn khi mở) và lượt đặt phải thuộc một trong các bàn đó (`PosSessionService.php:110-186, 238-241`). Nhưng UI `OpenSessionModal` chỉ có một bàn; không có thao tác ghép bàn trên POS.
- **Đề xuất:** UI chọn thêm bàn khi mở phiên; đổi bàn cho lượt đặt khi bàn đặt không đủ chỗ.
- **Cần chốt:** ai được ghép bàn (nhân viên hay chỉ quản lý); ghép bàn có giải phóng lượt đặt khác của bàn được ghép không.
- **Test nghiệm thu:** mở phiên 2 bàn cho 7 khách qua UI; bàn thứ hai có lượt đặt trong khung giờ → 409.

### RSV-11 · Khách muốn đổi lịch hoặc hủy sát giờ — **B** (P3)
- **Hiện tại:** khách bị chặn trong 60 phút trước giờ hẹn (`Customer/ReservationService.php:156-165`). Thông báo lỗi bảo liên hệ nhà hàng, nhưng phía nhà hàng chỉ admin có nút "Hủy lịch"; không ai sửa được giờ/bàn.
- **Đề xuất:** cho nhân viên/admin đổi giờ, bàn, số khách của lượt đặt (có kiểm tra xung đột, ghi người sửa).
- **Cần chốt:** hủy sát giờ có tính no-show/cọc không.

### RSV-12 · Nhà hàng phải hủy vì bàn hỏng/sự cố — **B** (P3)
- **Hiện tại:** admin không chuyển được bàn sang bảo trì khi bàn còn lượt đặt hôm nay (`Admin/TableService.php:75`) — phải hủy từng lượt trước. API hủy không bắt buộc lý do (`adminNote` nullable); UI bắt buộc ≥3 ký tự. Không có đổi bàn cho lượt đặt, không có thông báo cho khách.
- **Đề xuất:** thao tác "chuyển lượt đặt sang bàn khác" khi bàn gặp sự cố; bắt buộc lý do ở API khi nhà hàng hủy.
- **Cần chốt:** nhà hàng hủy có cần liên hệ/ưu đãi cho khách không (ngoài phần mềm).

### RSV-13 · Bàn có khách ngồi quá lâu, ảnh hưởng lượt đặt tiếp theo — **C** (P2)
- **Hiện tại:** không có khái niệm thời lượng dự kiến của phiên; khách vãng lai mở lúc 16:59 cho bàn có lượt đặt 19:00 là hợp lệ (chỉ chặn từ 17:01) mà không có cảnh báo "phải trả bàn trước 19:00". POS không cảnh báo khi phiên kéo dài sát giờ lượt kế tiếp.
- **Bằng chứng:** `TableStatusSynchronizer.php:51-60`; `Staff/PosTableResource.php` (`upcomingReservation` chỉ hiện khi bàn không có phiên).
- **Đề xuất:** khi mở phiên trên bàn có lượt đặt cùng ngày, hiển thị "phải trả bàn lúc HH:MM"; thẻ bàn đang phục vụ cảnh báo khi còn < 30 phút tới lượt kế tiếp.
- **Cần chốt:** thời lượng dự kiến theo số khách (đề xuất 90 phút ≤ 4 khách, 120 phút > 4 khách).

### RSV-14 · Bàn đặt vẫn có khách cũ, không nhận được khách đặt sang bàn khác — **D** (P2)
- **Tình huống:** Bàn T01 đặt 19:00; nhóm khách ăn trưa kéo dài, 19:00 vẫn ngồi. Bàn T02 trống.
- **Hiện tại:** không mở phiên được trên T01 (đang `OCCUPIED`); mở trên T02 kèm `reservationId` bị từ chối *"The reservation belongs to a different table."*; admin không đổi được bàn của lượt đặt; khách cũng không sửa được (còn < 60 phút).
- **Bằng chứng:** Repro `test_rsv_a_reservation_cannot_be_seated_at_another_table_when_its_table_is_still_busy`; `PosSessionService.php:238-241`.
- **Ảnh hưởng:** nhân viên buộc phải nhận khách như vãng lai trên T02 (mất liên kết lượt đặt), trong khi T01 vẫn bị giữ cho lượt đặt "chưa đến".
- **Đề xuất:** khi nhận khách, cho phép chọn bàn khác (đủ chỗ, không xung đột), ghi lý do và cập nhật `DAT_BAN.ma_ban`.
- **Test nghiệm thu:** nhận lượt đặt T01 sang T02 → phiên gắn lượt đặt, T01 hết bị giữ, lượt đặt ghi bàn mới.

### RSV-15 · Khách đã được nhận bàn nhưng admin/khách tiếp tục sửa hoặc hủy — **D** (P3)
- **Admin:** bị chặn đúng (`Admin/ReservationService.php:69-74`) — **A**.
- **Khách:** vẫn hủy/sửa được nếu còn ≥ 60 phút trước giờ hẹn (ví dụ được nhận bàn sớm theo RSV-07). Lượt đặt chuyển `CANCELLED` trong khi phiên vẫn mở; khi đóng phiên, `BillingService::close` không chuyển được lượt sang `COMPLETED` (từ CANCELLED) nên lịch sử sai.
- **Bằng chứng:** Repro `test_rsv_customer_can_cancel_a_reservation_that_is_already_seated`; `Customer/ReservationService.php:156-165` không kiểm tra phiên phục vụ.
- **Đề xuất:** khóa sửa/hủy phía khách khi lượt đặt đã gắn phiên không bị hủy; `canModify = false` trong `ReservationResource`.
- **Test nghiệm thu:** lượt đã nhận bàn → khách hủy trả 409.

### RSV-16 · Đặt ngoài giờ mở cửa, sát giờ đóng cửa hoặc sang ngày hôm sau — **C** (P3)
- **Hiện tại:** không có cấu hình giờ mở cửa; đặt 03:00 sáng thành công. Đặt 22:50 cũng hợp lệ dù nhà hàng có thể đóng 22:00. Nhận khách lượt đặt chỉ trong cùng ngày (`isSameDay`), nên lượt 23:30 và khách đến 00:10 không nhận được.
- **Bằng chứng:** Repro `test_rsv_one_customer_can_hold_many_tables_and_book_outside_opening_hours`; `CreateReservationRequest` không có ràng buộc giờ.
- **Đề xuất:** cấu hình giờ mở cửa và giờ nhận khách cuối cùng; validate ở cả API tìm bàn và tạo lượt đặt.
- **Cần chốt:** giờ mở cửa theo ngày trong tuần, giờ nhận khách cuối.

### RSV-17 · Khách đặt một bàn đang có khách vãng lai ngồi, giờ đến sau 30 phút — **D** (P2)
- **Hiện tại:** API tìm bàn trống và tạo lượt đặt chỉ loại bàn `MAINTENANCE` và lượt đặt chồng; **không xét phiên phục vụ đang mở**. Bàn `OCCUPIED` vẫn được gợi ý và đặt thành công cho 35 phút sau.
- **Bằng chứng:** Repro `test_rsv_customer_can_book_a_table_that_is_occupied_right_now`; `Customer/ReservationService.php:25-45, 186-195`.
- **Ảnh hưởng:** khách đặt online đến nơi thì bàn còn người; kết hợp RSV-14 thì nhân viên không có cách xử lý chuẩn.
- **Đề xuất:** với lượt đặt trong vài giờ tới, loại các bàn đang có phiên mở (hoặc chỉ cho đặt sau thời lượng dự kiến còn lại của phiên).
- **Test nghiệm thu:** bàn đang `OCCUPIED` không xuất hiện trong kết quả tìm bàn cho 1 giờ tới; tạo lượt đặt trả 409.

### RSV-18 · Ghi chú nội bộ của admin lộ cho khách và làm đổi luật đặt trước — **D** (P4)
- **Hiện tại:** `adminNote` được nối vào `DAT_BAN.ghi_chu` dạng `[ADMIN] …` (`Admin/ReservationService.php:99-101`). Khách thấy ghi chú này (`CustomerReservationsPage.jsx:25`) và form sửa của khách điền sẵn nên có thể xóa/sửa ghi chú của admin. Nếu ghi chú admin chứa "VIP" hoặc "sinh nhật" thì lần khách sửa tiếp theo bị áp luật đặt trước 2 giờ (`Customer/ReservationService.php:178-184`).
- **Bằng chứng:** Repro `test_rsv_admin_can_complete_…` (khách đọc được "[ADMIN] Khách không đến, VIP cũ").
- **Đề xuất:** tách ghi chú nội bộ ra cột riêng; chỉ hiện cho khách phần ghi chú dành cho khách.

### RSV-19 · Một hằng 119 phút cho bốn mục đích — **D** (P3)
- Xem mục 2. **Đề xuất:** tách thành `dining.reservation.slot_minutes`, `grace_minutes`, `early_seating_minutes`, `confirm_deadline_minutes`.

---

## 4. Phiên phục vụ và bàn (SES)

### SES-01 · Mở trùng phiên — **A** (P2)
- Khóa các bàn, kiểm tra phiên mở, `Idempotency-Key` + `request_hash` (`PosSessionService.php:110-186`). Test `PosFloorApiTest::test_staff_can_open_a_session_and_idempotently_retry`, `LiveDiningApiTest::test_check_in_never_opens_two_sessions_for_one_table`.

### SES-02 · Mở nhầm bàn — **B** (P3)
- Trước khi gửi bếp: "Hủy phiên" có lý do → **A** (`PosSessionService.php:188-229`). Sau khi đã gửi bếp: không hủy được, không đổi bàn được (SES-05) → phải thanh toán trên bàn sai.

### SES-03 · Nhận khách đặt trước với số khách thực tế khác — **A** (P4). Xem RSV-09.

### SES-04 · Khách đến thêm hoặc về bớt trong lúc ăn — **C** (P4)
- Không có API cập nhật `so_luong_khach` sau khi mở (Repro `test_pos_no_routes_exist_to_change_guests_move_or_merge_tables`). Ảnh hưởng chủ yếu tới số liệu và giới hạn sức chứa.
- **Đề xuất:** `PATCH /staff/pos/sessions/{id}` cho số khách (kiểm tra sức chứa, ghi người sửa).

### SES-05 · Đổi bàn khi chưa và khi đã gọi món — **C** (P3)
- Không có API/UI đổi bàn. Món, đơn, hóa đơn, yêu cầu phục vụ và visit QR đều gắn với phiên, nên đổi bàn chỉ cần đổi bản ghi `PHIEN_BAN` — dữ liệu cho phép, nghiệp vụ chưa có.
- **Đề xuất:** "Chuyển bàn" trong phiên (bàn đích AVAILABLE, không xung đột lượt đặt, đủ chỗ); bàn cũ → `CLEANING`; ghi lịch sử; thiết bị QR của bàn cũ giữ quyền vào phiên (vì visit gắn phiên), mã QR bàn mới dùng cùng phiên.
- **Cần chốt:** QR in ở bàn cũ có còn cho tham gia phiên không (đề xuất: không — chỉ bàn mới).
- **Test nghiệm thu:** chuyển bàn khi có món đang nấu → KDS hiện tên bàn mới; bàn cũ CLEANING; khách QR bàn cũ không join được phiên.

### SES-06 · Ghép/tách bàn trước và trong lúc phục vụ — **B** (P4)
- Ghép khi mở: API có, UI không (RSV-10). Ghép/tách giữa phiên: không có. Lệnh demo đã từ chối phiên ghép đúng (ADR-022).

### SES-07 · Bàn chờ vệ sinh chưa xác nhận sạch nhưng có khách mới — **A** (P4)
- Mở phiên chỉ nhận AVAILABLE/RESERVED (`PosSessionService.php:142`); nút "Đã vệ sinh · mở lại bàn" một chạm. Khách QR vẫn gửi được yêu cầu nhận bàn (nhân viên phải dọn trước khi chấp nhận).

### SES-08 · Bàn gặp sự cố giữa phiên — **C** (P3)
- Admin không chuyển được bàn đang có phiên sang bảo trì (`Admin/TableService.php`, ADR-017) và không có đổi bàn → chỉ có thể tiếp tục trên bàn hỏng. Phụ thuộc SES-05.

### SES-09 · Khách bỏ đi khi đã có món hoặc chưa thanh toán — **C** (P2)
- Không hủy được phiên khi đã có món gửi bếp; đóng phiên bắt buộc hóa đơn `PAID` (`BillingService.php:161-181`). Nhân viên chỉ còn cách "thu tiền giả" (ghi nhận tiền mặt/chuyển khoản không có thật) để giải phóng bàn → doanh thu sai.
- **Đề xuất:** "Đóng phiên chưa thanh toán" có lý do, cần quyền quản lý; hóa đơn chuyển `UNPAID_WRITE_OFF`, không tính doanh thu, có báo cáo thất thu.
- **Cần chốt:** ai được duyệt, có ghi công nợ khách quen không.
- **Test nghiệm thu:** đóng phiên chưa trả với lý do → bàn CLEANING, doanh thu không tăng, có bản ghi thất thu.

### SES-10 · Hai nhân viên cùng thao tác trên một phiên — **A/E** (P3)
- Thêm món dùng `clientRequestId` (unique), gửi bếp và hóa đơn khóa đơn/phiên, đóng phiên có `expectedVersion`. Chưa có test đồng thời thật (mục 0).

### SES-11 · Phiên kéo dài qua ngày — **E** (P4)
- Báo cáo tính theo `ngay_dat_hang` (đơn POS tạo khi mở order), `getFloor` lọc lượt đặt tới `endOfDay`. Chưa thấy lỗi cụ thể nhưng không có test; doanh thu phiên 23:30–00:30 rơi vào ngày mở order thay vì ngày thanh toán (liên quan PAY-16).

---

## 5. QR tại bàn (QR)

Nhóm này đã được kiểm tra sâu ở milestone Live Dining và review ADR-022. Bằng chứng là các test trong `tests/Feature/LiveDiningApiTest.php` và E2E `restaurant-fe/scripts/live-dining-e2e.cjs`.

| Mã | Tình huống | Phân loại | Bằng chứng / ghi chú |
|---|---|---|---|
| QR-01 | Người giữ link QR cũ, mã phiên cũ, visit token cũ | **A** | `test_a_previous_guest_holding_the_fixed_qr_cannot_enter_the_next_meal`, `test_a_pending_check_in_cannot_slip_into_…`, `test_a_guest_pass_only_works_on_its_own_table`. |
| QR-02 | Nhân viên tạo lại mã bàn khi khách đang dùng | **A** | Thiết bị đã trong phiên dùng tiếp (`GuestAccessService.php:151-153`); `test_wrong_table_codes_lock_…`. |
| QR-02b | Admin tạo lại **mã QR bàn** khi khách đang ngồi | **B** | Link cũ trả 404 ngay (`test_rotating_the_qr_token_revokes_the_printed_code`); khách đang ngồi phải quét mã mới và nhập lại mã bàn. Đúng thiết kế thu hồi, nhưng không có cảnh báo cho admin "bàn đang có N thiết bị". |
| QR-03 | Thiết bị mới, mất token, trình duyệt chặn storage | **B** | Mất token → nhập lại mã bàn. Chặn storage → không gửi món được (`useRoundSubmission.js:115-116`), khách phải gọi nhân viên; chấp nhận được. |
| QR-04 | Nhiều thiết bị cùng gọi món | **A** | Mỗi lượt là một `PHIEU_BEP`, khóa phiên (`TableOrderService.php:80-82`). |
| QR-05 | Double click, mất phản hồi, reload, nhiều tab | **A** | `test_repeating_a_round_with_the_same_idempotency_key_…`, `test_a_lost_response_retry_…`, E2E bước "mất phản hồi + reload". |
| QR-06 | Khách sửa giỏ trong lúc đang gửi | **A** | Lượt đã chốt tách khỏi giỏ (ADR-022). |
| QR-07 | Món đổi giá/hết bán trước lúc gửi | **A / B** | Hết bán → 409 kèm tên món, món trả về giỏ (A). Đổi giá → backend lấy giá mới đúng nhưng giỏ vẫn hiện giá cũ, khách không được báo (B, giống MNU-02). |
| QR-08 | Phiên chuyển chờ thanh toán/đóng trong lúc request đang chạy | **A** | Khóa phiên trước khi ghi (`TableOrderService.php:80-94`); replay chạy trước kiểm tra phiên. |
| QR-09 | Yêu cầu phục vụ trùng, nhân viên nhận đồng thời, khách hủy đồng thời | **A** | Dedupe + cooldown (`ServiceRequestService.php:53-75`), khóa dòng khi nhận/hoàn tất/hủy; `test_service_requests_are_deduplicated_and_cooled_down`. |
| QR-10 | Yêu cầu thanh toán khi khách vẫn muốn gọi thêm | **B** | Lập hóa đơn khóa gọi món; khách được báo "gọi nhân viên"; nhân viên dùng "Gọi thêm món" (reopen). Không có cách để khách tự hủy yêu cầu thanh toán đã được nhận. |
| QR-11 | Lộ món/hóa đơn của phiên trước sang phiên sau | **A** | `test_bill_flow_keeps_other_tables_private_…`, E2E kiểm tra khóa riêng tư trên mọi phản hồi công khai. |

---

## 6. POS và bếp (KDS)

### KDS-01 · Gọi nhầm món trước và sau khi gửi bếp — **A trước / C sau** (P1)
- **Trước khi gửi:** sửa số lượng, ghi chú, xóa dòng nháp (`PosOrderService.php:91-118`) — A.
- **Sau khi gửi:** không xóa, không giảm số lượng (`assertDraftItem`, dòng 186-189), không hủy phiên, bếp không từ chối được (`KitchenService.php:19-24` chỉ có bước tiến). Món bị tính đủ vào hóa đơn.
- **Bằng chứng:** Repro `test_kds_a_mistaken_item_sent_to_the_kitchen_cannot_be_voided_and_is_billed`.
- **Ảnh hưởng:** khách trả tiền món không dùng, hoặc nhân viên phải "hoàn tiền" thủ công ngoài hệ thống.
- **Đề xuất:** "Hủy món" theo từng dòng với lý do: trước khi bếp bắt đầu (`SENT/QUEUED`) nhân viên tự hủy; khi đang nấu hoặc đã phục vụ cần quyền quản lý; món hủy không tính tiền, có thống kê hao hụt.
- **Cần chốt:** ngưỡng cần duyệt; món đã nấu bị hủy có tính tiền không.
- **Test nghiệm thu:** hủy dòng `SENT` → không còn trong hóa đơn, KDS ẩn món; hủy dòng `PREPARING` bởi STAFF → 403, bởi quản lý → OK kèm lý do.

### KDS-02 · Hủy món trước khi nấu, đang nấu hoặc đã phục vụ — **C** (P1). Cùng gốc với KDS-01.

### KDS-03 · Bếp hết nguyên liệu sau khi nhận món — **D** (P2)
- **Tình huống:** món đã ở `QUEUED`, bếp báo hết.
- **Hiện tại:** không có trạng thái từ chối khả dụng (`REJECTED` có trong enum nhưng không API nào đặt được). Món không thể `READY`, nên **phiên không bao giờ đóng được** (`BillingService.php:178-182`) dù khách đã trả tiền.
- **Bằng chứng:** Repro `test_kds_an_item_that_can_never_be_cooked_blocks_closing_the_session`.
- **Ảnh hưởng:** bàn kẹt `OCCUPIED`; buộc phải đánh dấu `READY` sai sự thật.
- **Đề xuất:** "Bếp từ chối món" (`REJECTED`, lý do bắt buộc), tự trừ khỏi tổng; tạm đánh dấu món hết bán; báo cho POS/khách QR.
- **Test nghiệm thu:** bếp từ chối món `QUEUED` → tổng đơn giảm, phiên đóng được, khách QR thấy "Bếp từ chối" kèm lý do.

### KDS-04 · Bếp từ chối món, món phải làm lại hoặc khách đổi món — **C** (P2)
- Không có làm lại (remake) hay đổi món. **Đề xuất:** "Làm lại" tạo dòng mới giá 0 liên kết dòng cũ (lý do: rơi/đổ/sai); "Đổi món" = hủy dòng cũ (KDS-01) + thêm dòng mới.
- **Cần chốt:** món làm lại có tính tiền không.

### KDS-05 · Một dòng nhiều phần nhưng chỉ phục vụ được một phần — **C** (P4)
- Trạng thái bếp theo dòng, không theo phần. **Đề xuất:** tách dòng (split) khi phục vụ một phần.

### KDS-06 · Một món trễ trong khi các món khác đã xong — **A / B** (P4)
- SLA theo món, hiển thị trên KDS và khách (A). Chưa có cảnh báo chủ động cho quản lý (B).

### KDS-07 · Nhân viên bấm sai trạng thái — **B** (P3)
- Chỉ đi tiến (`KitchenService.php:19-24, 77-80`); bấm nhầm `SERVED` không hoàn tác được. **Đề xuất:** hoàn tác bước gần nhất trong 1–2 phút (ghi người thao tác).

### KDS-08 · Hai người cùng chuyển trạng thái — **A** (P4)
- Khóa đơn rồi món, kiểm tra bước kế tiếp, người thứ hai nhận 409 (`KitchenService.php:69-89`).

### KDS-09 · Gọi thêm khi đơn READY hoặc đã lập hóa đơn — **A** (P3)
- READY nhận thêm món (`PosOrderService.php` `assertOrderAcceptsItems`); hóa đơn → "Gọi thêm món" (reopen, VOID) — `PosReservationAndBillingApiTest::test_reopening_an_unpaid_invoice_…`.

### KDS-10 · Chốt tiền khi còn món nháp/đang nấu — **A** (P4)
- Có món nháp thì không lập được hóa đơn; khi còn món đang nấu vẫn thu được tiền (trả trước) nhưng đóng phiên bắt buộc mọi món READY/SERVED. Hợp lý, tuy nhiên phụ thuộc KDS-03.

### KDS-11 · Phân biệt hủy món, xóa dòng nháp và hoàn tiền — **C** (P1)
- Chỉ có "xóa dòng nháp" (xóa vật lý dòng `DRAFT`) và "hoàn tiền" (toàn bộ giao dịch, PAY-14). Không có hủy món sau gửi bếp. Xem đề xuất KDS-01 và PAY-14.

### KDS-12 · Đơn hẹn giờ được đưa xuống bếp ngay — **C** (P3)
- **Tình huống:** khách đặt tự lấy lúc 12:00 ngày mai; admin xác nhận ngay hôm nay.
- **Hiện tại:** món xuất hiện trên KDS ngay khi xác nhận (`KitchenService.php:31-63` không xét `thoi_gian_nhan_du_kien`); `scheduledAt` chỉ yêu cầu `after:now`, không giới hạn xa/gần hay giờ mở cửa.
- **Bằng chứng:** Repro `test_kds_a_pickup_scheduled_for_tomorrow_reaches_the_kitchen_immediately`.
- **Đề xuất:** đơn hẹn giờ chỉ hiện trên KDS từ `scheduledAt − thời gian chuẩn bị`; giới hạn hẹn trong giờ mở cửa và tối đa N ngày.
- **Cần chốt:** thời gian chuẩn bị mặc định (đề xuất 30 phút), hẹn trước tối đa bao lâu.

---

## 7. Giao hàng và tự đến lấy (DLV)

### DLV-01 · Khách sửa địa chỉ/SĐT sau khi đặt — **C** (P3)
- Không có API sửa người nhận/địa chỉ; admin cũng không sửa được. Chỉ có cách hủy (khi còn `PENDING_CONFIRMATION`) và đặt lại.
- **Đề xuất:** cho sửa trước khi giao cho tài xế; ghi lịch sử.

### DLV-02 · Địa chỉ ngoài phạm vi phục vụ — **C** (P3)
- `phi_giao_hang` luôn 0 (`Customer/OrderService.php:78`), không kiểm tra vùng giao. **Cần chốt:** phạm vi và bảng phí.

### DLV-03 · Không tìm được tài xế — **B** (P3)
- Đơn ở `AWAITING_DRIVER` vô thời hạn; admin hủy được (READY được phép hủy) nhưng không có trạng thái/ghi nhận riêng, không cảnh báo theo thời gian chờ.

### DLV-04 · Tài xế nhận rồi hủy, giao thất bại, không liên lạc được, khách từ chối nhận — **D** (P2)
- **Hiện tại:** `OUT_FOR_DELIVERY` chỉ đi tới `DELIVERED` (`FulfillmentStatus.php:21`); admin **không hủy được** đơn đang giao (`Admin/OrderService.php:140-142`). Không có trạng thái thất bại/hoàn trả.
- **Bằng chứng:** Repro `test_dlv_switching_a_cash_order_to_qr_mid_delivery_leaves_it_stuck` (phần hủy bị chặn); `Admin/OrderService.php:90-127`.
- **Ảnh hưởng:** đơn giao thất bại chỉ có thể bị ghi sai thành `DELIVERED` (và với đơn tiền mặt, `settlePaymentForCompletion` còn ghi nhận đã thu tiền) hoặc nằm mãi ở "đang giao".
- **Đề xuất:** thêm `DELIVERY_FAILED` (lý do bắt buộc) → hoặc giao lại (`AWAITING_DRIVER`) hoặc hủy (đơn QR đã trả → chờ hoàn tiền).
- **Cần chốt:** đơn giao thất bại do khách có tính phí không; số lần giao lại tối đa.
- **Test nghiệm thu:** `OUT_FOR_DELIVERY → DELIVERY_FAILED → AWAITING_DRIVER`; `DELIVERY_FAILED → CANCELLED` với đơn QR → `refundsPending` tăng; đơn tiền mặt không bị ghi đã thu.

### DLV-05 · Khách xin hủy khi đã nấu/đang giao — **B** (P3)
- Khách chỉ tự hủy khi `PENDING_CONFIRMATION`; sau đó nhận thông báo liên hệ nhà hàng. Admin hủy được tới READY (có lý do), không hủy được khi đang giao (DLV-04).

### DLV-06 · Khách tự lấy đến sớm, muộn hoặc không đến — **B** (P3)
- `AWAITING_CUSTOMER` không hết hạn, không ghi thời điểm sẵn sàng. Admin hủy thủ công được. **Cần chốt:** giữ món tối đa bao lâu, không đến thì đơn QR đã trả xử lý sao.

### DLV-07 · Người khác đến lấy hộ — **C** (P4)
- Không có mã nhận hàng. **Đề xuất:** mã 4 số trên đơn tự lấy, nhân viên nhập khi bàn giao.

### DLV-08 · Món đã xong nhưng chưa bàn giao quá lâu — **B** (P4)
- Dashboard đếm `readyForHandoff`, không có tuổi của đơn hay cảnh báo.

### DLV-09 · Đơn giao thiếu/sai món — **C** (P3)
- Không có khiếu nại/bồi hoàn theo dòng; chỉ hoàn toàn bộ (PAY-14).

### DLV-10 · Khách đổi đơn tiền mặt sang QR khi tài xế đang giao — **D** (P2)
- **Tình huống:** đơn giao tiền mặt, tài xế đang giao; khách mở trang thanh toán và bấm tạo QR.
- **Hiện tại:** `POST /customer/payments/{id}/qr` đổi phương thức sang `BANK_TRANSFER_QR` ở **mọi** trạng thái chưa hủy/hoàn tất (`Customer/PaymentService.php:31-49, 41`). Sau đó tài xế không hoàn tất được (yêu cầu QR đã trả — `Admin/OrderService.php:196-224`) và admin không hủy được (DLV-04) → **đơn kẹt**.
- **Bằng chứng:** Repro `test_dlv_switching_a_cash_order_to_qr_mid_delivery_leaves_it_stuck`.
- **Đề xuất:** chỉ cho đổi phương thức trước khi đơn được xác nhận (hoặc trước khi giao cho tài xế); sau đó QR chỉ là "trả trước tùy chọn" và không chặn thu tiền mặt.
- **Test nghiệm thu:** đơn `OUT_FOR_DELIVERY` → tạo QR trả 409; đơn `PENDING_CONFIRMATION` → đổi được.

---

## 8. Hóa đơn, thanh toán và hoàn tiền (PAY)

> Gốc chung của nhiều lỗi: `THANH_TOAN` quan hệ **1–1** với đơn (`ma_don_hang` unique, migration `2026_10_19_060000_create_restaurant_system_tables.php:133`), không có cột thời gian, không có người thao tác, `ma_giao_dich` không unique (dòng 134), `log_callback` chỉ một ô văn bản bị ghi đè. Mọi tiền về "ngoài kịch bản" (lần hai, dư, thiếu, sai hóa đơn) **không có chỗ để lưu**.

### PAY-01 · Lập hóa đơn hai lần — **A** (P1)
- Hóa đơn đang mở được trả lại (`BillingService.php:29-80`); `HOA_DON.ma_phien` unique.

### PAY-02 · Gọi thêm sau khi lập hóa đơn — **A** (P1)
- Reopen → VOID, lập lại tính tổng mới trên cùng mã (`BillingService.php:85-105`); test `test_reopening_an_unpaid_invoice_allows_ordering_more_then_reinvoices_the_new_total`.

### PAY-03 · Khách quét QR cũ sau khi hóa đơn bị mở lại hoặc lập lại — **D** (P1)
- **Tình huống:** hóa đơn HD12 = 158.000 ₫; khách chụp QR; nhân viên mở lại để gọi thêm (VOID) rồi lập lại HD12 = 237.000 ₫.
- **Hiện tại:**
  - Chuyển khoản theo QR cũ lúc hóa đơn đang VOID → webhook 409, **không lưu gì** (Repro `test_pay_money_for_a_voided_invoice_is_rejected_without_a_trace`).
  - Chuyển 158.000 ₫ theo QR cũ sau khi lập lại (cùng nội dung `HD12`) → webhook 400 "thiếu tiền", **không lưu gì** (Repro `test_pay_an_underpaid_table_invoice_transfer_leaves_no_record`). Luồng đơn online thì có ghi `PAID_AMOUNT_TOO_LOW` vào `log_callback`, luồng hóa đơn tại bàn thì không (`Customer/PaymentService.php:222-240`).
- **Ảnh hưởng:** tiền đã vào tài khoản nhà hàng nhưng hệ thống không biết; nhân viên dễ thu tiền lần nữa.
- **Đề xuất:** sổ giao dịch nhận tiền (mục 10.4) ghi **mọi** callback hợp lệ chữ ký kể cả bị từ chối áp dụng; nội dung chuyển khoản có phiên bản hóa đơn (`HD12-2`) để nhận ra QR cũ; POS hiển thị "có khoản chuyển chưa khớp" cho phiên.
- **Test nghiệm thu:** callback cho hóa đơn VOID → bản ghi `UNMATCHED` gắn phiên; POS thấy cảnh báo; áp dụng thủ công vào hóa đơn mới được.

### PAY-04 · Thanh toán trong lúc nhân viên mở lại hóa đơn — **A/E** (P1)
- Cả hai thao tác khóa `HOA_DON`; ai trước người đó thắng, người sau 409 (`BillingService.php:85-160`). Chưa có test đồng thời thật.

### PAY-05 · Tiền mặt thiếu, đủ, dư; tiền thối — **A** (P3)
- POS tính tiền thối, chỉ cho xác nhận khi đủ (`BillingPanel.jsx:27-31`); backend ghi đúng tổng hóa đơn (`BillingService.php:124-128`). Không lưu số tiền khách đưa/tiền thối (chấp nhận được với MVP).

### PAY-06 · Khách chuyển ít hơn hoặc nhiều hơn số tiền — **D** (P1)
- **Đơn online chuyển dư 500.000 ₫:** được `PAID` với `so_tien` = giá trị đơn, phần dư chỉ nằm trong payload `log_callback`, không có trạng thái/cảnh báo hoàn phần dư (Repro `test_pay_an_overpaid_order_is_marked_paid_for_the_order_amount_only`).
- **Hóa đơn tại bàn chuyển dư:** `BillingService::pay` ghi `amount = expected` (`Customer/PaymentService.php:245`), phần dư mất.
- **Chuyển thiếu:** đơn online có log (test `PaymentWebhookApiTest::test_underpaid_transfer_is_logged_but_not_marked_paid`), hóa đơn tại bàn không (PAY-03).
- **Ảnh hưởng:** chênh lệch tiền không có trên báo cáo; không hoàn được phần dư một cách có kiểm soát.
- **Đề xuất:** ghi `so_tien_nhan` thực tế; dư → "chờ hoàn phần dư"; thiếu → "thanh toán một phần" cho phép thu bù.
- **Cần chốt:** dung sai (ví dụ ±1.000 ₫) và xử lý khi thiếu (thu bù bằng tiền mặt được không).

### PAY-07 · Khách chuyển khoản hai lần — **D** (P1)
- Callback lần hai (mã giao dịch khác) được trả 200, `ma_giao_dich` và `log_callback` giữ nguyên lần đầu — **lần chuyển thứ hai không có dấu vết** (Repro `test_pay_a_second_transfer_for_a_paid_order_is_silently_absorbed`; `Customer/PaymentService.php:152-154`). Hóa đơn tại bàn đã `PAID` cũng vậy (`processInvoiceTransfer` trả payment cũ).
- **Đề xuất:** idempotent theo **mã giao dịch ngân hàng**, không theo đơn: cùng mã → bỏ qua; mã khác → ghi `DUPLICATE_PAYMENT`, đưa vào "chờ hoàn".
- **Test nghiệm thu:** hai callback khác mã cho cùng đơn → 2 bản ghi nhận tiền, đơn `PAID`, 1 khoản chờ hoàn.

### PAY-08 · Callback trùng, đến chậm hoặc sai thứ tự — **A** (P1)
- Cùng callback lặp lại không đổi dữ liệu (idempotent theo đơn); chậm/sai thứ tự vẫn khớp theo `DH{id}`/`HD{id}`. Ngoại lệ: mã khác → PAY-07.

### PAY-09 · Tiền về sau khi đơn đã hủy hoặc phiên đã kết thúc — **A/B (đơn) · D (phiên)** (P1)
- Đơn online đã hủy: ghi `PAID` + `requiresRefund`, dashboard đếm `refundsPending` (A phát hiện, B xử lý thủ công).
- Phiên đã đóng (hóa đơn `PAID`): khoản chuyển mới bị nuốt như PAY-07. Hóa đơn VOID: PAY-03.
- Admin **không ghi nhận thủ công** được tiền về cho đơn đã hủy (`Admin/PaymentService.php:65-67`), dù đó là thao tác cần để hoàn tiền (B).

### PAY-10 · Xác nhận thủ công đồng thời với callback — **A/E** (P1)
- Cả hai khóa dòng `THANH_TOAN`; người sau thấy `PAID` (thủ công nhận 409, webhook idempotent). Chưa có test đồng thời thật.

### PAY-11 · Một mã giao dịch dùng cho hai hóa đơn — **D** (P1)
- Admin xác nhận hai đơn khác nhau bằng cùng mã `FT26102199999` đều thành công (Repro `test_pay_the_same_bank_transaction_code_can_confirm_two_payments`; `Admin/PaymentService.php:47-86`; cột không unique). POS cũng nhận mã bất kỳ (PAY-13).
- **Ảnh hưởng:** một khoản tiền có thể "trả" cho nhiều đơn — lỗ hổng gian lận nội bộ.
- **Đề xuất:** mã giao dịch ngân hàng unique toàn hệ thống (trừ mã hệ thống tự sinh cho tiền mặt); trùng → 409 kèm đơn đang dùng mã.

### PAY-12 · Khách đổi phương thức thanh toán — **A (POS) · D (online)** (P2)
- POS chọn phương thức lúc thu tiền (A). Online xem DLV-10.

### PAY-13 · POS ghi nhận chuyển khoản bằng mã tùy ý, không lưu người thu — **C** (P1)
- `transactionCode = "x"` được chấp nhận; `THANH_TOAN` không có cột nhân viên/thời điểm (Repro `test_pay_pos_transfer_is_recorded_with_any_code_and_no_cashier`; `BillingService.php:108-160`). `HOA_DON.thanh_toan_luc` có thời điểm nhưng không có người.
- **Đề xuất:** lưu nhân viên + thời điểm cho mọi ghi nhận thu tiền; mã giao dịch chuyển khoản tối thiểu 6 ký tự, unique (PAY-11); với QR tại bàn ưu tiên khớp tự động bằng webhook.

### PAY-14 · Hoàn toàn bộ hoặc một phần, hoàn lặp — **A (lặp) · C (một phần)** (P1)
- Hoàn lặp bị chặn (`Admin/PaymentService.php:119-121`) — A.
- Chỉ hoàn toàn bộ: không có số tiền hoàn, không người hoàn, không thời điểm hoàn (Repro `test_pay_paid_then_cancelled_order_counts_as_revenue_and_refund_is_a_status_flip`).

### PAY-15 · Hoàn tiền là ghi nhận thủ công hay chuyển tiền thật — **C** (P2)
- Chỉ đổi trạng thái `REFUNDED` + lý do; không kết nối ngân hàng, không lưu chứng từ hoàn. UI ghi "Hoàn tiền" có thể gây hiểu nhầm là hệ thống đã chuyển tiền.
- **Đề xuất:** gọi đúng tên "Ghi nhận đã hoàn tiền", bắt buộc phương thức + mã chứng từ hoàn.

### PAY-16 · Doanh thu/tiền hoàn có tính đúng sau các ngoại lệ không — **D** (P1)
- Đơn đã trả rồi bị hủy vẫn nằm trong `totalPaidRevenue` cho tới khi hoàn (Repro `test_pay_paid_then_cancelled_…`; `Admin/ReportService.php:32-36`).
- Doanh thu và tiền hoàn gom theo **ngày đặt đơn**, không theo ngày thu/ngày hoàn (`ReportService.php:38-40, 83-86`) — `THANH_TOAN` không có cột thời gian.
- Tiền dư/lần hai/không khớp (PAY-03, 06, 07) không xuất hiện ở đâu.
- **Đề xuất:** doanh thu = tiền đã nhận − đã hoàn, theo ngày giao dịch; tách "đã thu nhưng chờ hoàn".

### PAY-17 · Đối soát ("reconcile") — **B** (P2)
- `reconcile` chỉ so `so_tien` với `tong_tien` nội bộ (`Admin/PaymentService.php:89-112`), không đối chiếu sao kê ngân hàng. Tên gọi dễ hiểu nhầm.

---

## 9. Thực đơn, quyền thao tác và lịch sử (MNU / AUTH / AUD)

### MNU-01 · Ẩn danh mục nhưng món vẫn đang bán — **D** (P2)
- Ẩn danh mục (`hien_thi = false`) chỉ ẩn danh mục; món bên trong vẫn xuất hiện ở `/customer/dishes` (dùng cho menu khách, POS và QR), thêm vào giỏ và checkout được. Xóa mềm danh mục thì có ẩn món (`Admin/CategoryService.php:126-136`) — hai thao tác cho kết quả khác nhau.
- **Bằng chứng:** Repro `test_mnu_dishes_of_a_hidden_category_are_still_listed_and_sold`; `Customer/MenuService.php:45-47`, `CartService::addItem`.
- **Đề xuất:** món chỉ bán khi cả món và danh mục đều hiển thị, chưa xóa (sửa trong `MenuService`, `CartService`, checkout, `TableOrderService`, `PosOrderService`).
- **Test nghiệm thu:** ẩn danh mục → món biến khỏi menu, thêm giỏ 400, checkout 400, POS/QR 409.

### MNU-02 · Đổi giá sau khi gọi món — **A (đúng giá) · B (không báo)** (P3)
- Đơn đã đặt giữ giá snapshot (A). Món trong giỏ được tính theo giá mới lúc checkout mà không báo khách (Repro `test_mnu_price_change_after_adding_to_cart_is_applied_silently_at_checkout`). QR tương tự.
- **Đề xuất:** giỏ hiển thị giá hiện tại; checkout gửi tổng tiền mong đợi, lệch thì trả 409 "giá đã thay đổi" để khách xác nhận.

### MNU-03 · Hết món trong lúc nhiều người đặt — **C** (P4)
- Chỉ có cờ còn bán/hết; không có tồn kho/số suất. Đây là giới hạn phạm vi (không làm module kho). **Cần chốt:** có cần "số suất còn lại" cho món giới hạn không.

### MNU-04 · Xóa mềm món/danh mục khi món đang trong giỏ — **A** (P4)
- Checkout và gửi món kiểm tra lại món (`Customer/OrderService.php:46-49`, `TableOrderService.php:103-111`).

### AUTH-01 · Khóa tài khoản nhân viên khi đang thao tác — **A** (P3)
- Khóa xóa toàn bộ token (`Admin/AccountService.php` ~dòng 81-85); middleware `account.active` chặn ngay request tiếp theo.

### AUTH-02 · Khách gọi trực tiếp API để bỏ qua điều kiện trên UI — **A (phân quyền/IDOR) · D (ngoại lệ)** (P1)
- Phân quyền role và lọc theo chủ sở hữu có test ở mọi nhóm. Ngoại lệ đã tìm thấy: RSV-07 (nhận bàn sớm qua API), DLV-10 (đổi phương thức), RSV-15 (hủy lượt đã nhận bàn).

### AUTH-03 · Quyền nhân viên và quản lý đối với hủy món, hủy đơn, xác nhận thanh toán, hoàn tiền — **C** (P1)
- Chỉ hai mức: STAFF và ADMIN. STAFF hủy phiên (có lý do), ghi nhận thu tiền bất kỳ (PAY-13); không có duyệt của quản lý cho thao tác nhạy cảm. STAFF **không** đánh dấu no-show/hủy lượt đặt được (RSV-04). ADMIN làm được mọi thứ, không có phân tách nhiệm vụ.
- **Cần chốt:** ma trận quyền (đề xuất ở mục 10.6).

### AUD-01 · Lịch sử có đủ người, thời gian, lý do để giải thích sự cố không — **C** (P1)
- Có: người tạo phiếu bếp (`PHIEU_BEP.ma_nhan_vien`), lý do hủy phiên (`ly_do_huy`), lý do hủy đơn nối vào ghi chú, lý do hoàn (`ly_do_hoan_tien`), người xác nhận chuyển khoản thủ công (trong `log_callback`).
- Thiếu: người/thời điểm hoàn tiền; người thu tiền tại POS; người đổi trạng thái bếp từng bước; người duyệt/hủy lượt đặt; `log_callback` bị **ghi đè** (mất payload webhook khi xác nhận thủ công và ngược lại); không có bảng nhật ký thao tác.
- **Đề xuất:** bảng `NHAT_KY_THAO_TAC` (actor, action, entity, before/after, reason, created_at) ghi từ các service hiện có.

---

## 10. Bộ chính sách đề xuất — **ĐỀ XUẤT, CHƯA ÁP DỤNG**

> Các con số dưới đây là giá trị khởi điểm để thảo luận, đặt trong `config/dining.php` để thay đổi được mà không sửa code.

### 10.1. Đặt bàn
| Tham số | Đề xuất |
|---|---|
| Thời lượng lượt dùng bàn (xung đột) | 90 phút (≤ 4 khách), 120 phút (> 4 khách). |
| Thời gian giữ chỗ khi khách trễ | 15 phút; sau đó POS hiện "trễ", bàn cho phép khách vãng lai kèm cảnh báo. |
| Gia hạn khi khách báo trễ | tối đa +30 phút, không chồng lượt kế tiếp. |
| Đánh dấu no-show | nhân viên/quản lý đánh dấu `NO_SHOW` sau thời gian giữ chỗ; hệ thống tự đánh dấu sau 60 phút. |
| Nhận bàn sớm | trong 30 phút trước giờ hẹn; sớm hơn → khách vãng lai hoặc quản lý duyệt. |
| Duyệt `PENDING` | hạn 30 phút trước giờ hẹn, quá hạn → `EXPIRED` + báo khách. |
| Giới hạn | 1 lượt / khách / khung giờ; tối đa 3 lượt đang hoạt động trong tương lai. |
| Giờ mở cửa | cấu hình theo ngày; giờ nhận khách cuối = đóng cửa − 60 phút. |
| Sau khi nhận bàn | khách không sửa/hủy được lượt đặt; `COMPLETED` chỉ khi có phiên phục vụ. |
| Đổi bàn khi nhận khách | nhân viên được chọn bàn khác đủ chỗ, không xung đột; ghi lý do. |
| Ghi chú | tách ghi chú nội bộ của nhà hàng khỏi ghi chú của khách. |

### 10.2. Phiên phục vụ
- Đổi số khách và chuyển bàn trong phiên (nhân viên), ghi lịch sử; bàn cũ → `CLEANING`.
- Đóng phiên chưa thanh toán (khách bỏ đi): quản lý duyệt, lý do bắt buộc, không tính doanh thu, có báo cáo thất thu.

### 10.3. Món và bếp
| Thao tác | Ai được làm | Tính tiền |
|---|---|---|
| Xóa dòng nháp | Nhân viên | Không |
| Hủy món `SENT/QUEUED` | Nhân viên, lý do bắt buộc | Không |
| Hủy món `PREPARING/READY/SERVED` | Quản lý duyệt, lý do bắt buộc | Không (ghi hao hụt) |
| Bếp từ chối (hết nguyên liệu) | Bếp, lý do bắt buộc; gợi ý tạm hết món | Không |
| Làm lại (rơi, sai) | Bếp/nhân viên | Dòng mới giá 0, liên kết dòng gốc |
| Hoàn tác bước bếp | Người vừa bấm, trong 2 phút | — |
| Đơn hẹn giờ | Hiện trên KDS từ `scheduledAt − 30 phút` | — |

### 10.4. Thanh toán
- **Sổ giao dịch nhận tiền** (mỗi khoản tiền thật một dòng): nguồn (webhook / thủ công / POS), mã giao dịch ngân hàng **unique**, số tiền nhận thực tế, thời điểm, người ghi nhận, trạng thái `APPLIED / UNMATCHED / OVERPAID / DUPLICATE / REFUND_PENDING / REFUNDED`. `THANH_TOAN` hiện tại trở thành trạng thái tổng hợp của đơn.
- Webhook idempotent theo mã giao dịch ngân hàng; mọi callback hợp lệ chữ ký đều được lưu dù không áp dụng được.
- Nội dung chuyển khoản hóa đơn tại bàn có số phiên bản (`HD12-2`); QR cũ được nhận diện là "QR hết hiệu lực" và chuyển `UNMATCHED`.
- Dung sai ±1.000 ₫; thiếu → thu bù; dư → chờ hoàn phần dư.
- Phương thức thanh toán online khóa sau khi đơn được xác nhận.
- Hoàn tiền: số tiền (toàn phần hoặc một phần), phương thức, mã chứng từ, người và thời điểm; nút ghi rõ "Ghi nhận đã hoàn".
- Báo cáo: doanh thu theo ngày nhận tiền, trừ hoàn theo ngày hoàn; dòng riêng "đã thu chờ hoàn".

### 10.5. Giao hàng / tự lấy
- Thêm `DELIVERY_FAILED` (lý do) → giao lại hoặc hủy; đơn đang giao hủy được khi đã ghi nhận thất bại.
- Sửa người nhận/địa chỉ trước khi giao cho tài xế.
- Đơn tự lấy: mã nhận hàng; giữ món tối đa 60 phút sau giờ hẹn rồi cảnh báo.

### 10.6. Ma trận quyền đề xuất
| Thao tác | STAFF | ADMIN (quản lý) |
|---|---|---|
| Mở/hủy phiên mở nhầm, đổi số khách, chuyển bàn | ✓ | ✓ |
| Hủy món chưa nấu | ✓ | ✓ |
| Hủy món đã nấu/đã phục vụ, đóng phiên chưa thanh toán | duyệt bởi quản lý | ✓ |
| Thu tiền mặt / ghi nhận chuyển khoản tại POS | ✓ (lưu người thu) | ✓ |
| Đánh dấu khách trễ / no-show / gia hạn | ✓ | ✓ |
| Xác nhận chuyển khoản thủ công đơn online, hoàn tiền | — | ✓ |
| Tạo lại mã QR bàn | — | ✓ |

---

## 11. Kết luận và thứ tự ưu tiên

**Đã làm tốt:** chống đặt trùng; mở phiên idempotent và chống mở trùng; toàn bộ nhóm QR (mã bàn theo phiên, chống gửi trùng qua reload, không lộ dữ liệu phiên trước); khóa dòng và máy trạng thái ở bếp, hóa đơn, yêu cầu phục vụ; webhook có chữ ký và idempotent theo đơn; snapshot giá; khóa tài khoản có hiệu lực ngay.

**Lỗi code có bằng chứng (D):**
- Tiền: PAY-03, PAY-06, PAY-07, PAY-11, PAY-16.
- Kẹt vận hành: DLV-04, DLV-10, KDS-03.
- Nhận khách: RSV-07, RSV-14, RSV-15, RSV-17.
- Khác: RSV-18, RSV-19, MNU-01.

**Khoảng trống nghiệp vụ quan trọng (C):** hủy món sau khi gửi bếp (KDS-01/02/11); khách bỏ đi (SES-09); no-show, khách trễ, hết hạn `PENDING` (RSV-03/04/06); đổi bàn (SES-05); đơn hẹn giờ (KDS-12); giờ mở cửa (RSV-16); ma trận quyền và nhật ký thao tác (AUTH-03, AUD-01).

**Chính sách cần người dùng chốt:**
- Thời gian giữ chỗ, cửa sổ nhận bàn sớm và thời lượng mặc định của lượt dùng bàn.
- Chính sách no-show và giới hạn lượt đặt của mỗi khách.
- Ngưỡng cần quản lý duyệt khi hủy món; món đã nấu bị hủy có tính tiền không.
- Dung sai tiền chuyển khoản; cách xử lý tiền thiếu và tiền dư.
- Hoàn tiền một phần; thời điểm khóa phương thức thanh toán online.
- Trạng thái giao thất bại và phí khi giao thất bại.
- Giờ mở cửa.

**Đề xuất 7 nhóm cho milestone tiếp theo (theo ưu tiên):**
1. **Sổ giao dịch nhận tiền và ngoại lệ chuyển khoản** (PAY-03/06/07/09/11/13/16): ghi mọi khoản tiền, mã giao dịch unique, QR có phiên bản, báo cáo theo ngày nhận tiền. *(P1 – sai tiền)*
2. **Hủy, từ chối và làm lại món có lý do + quyền duyệt** (KDS-01/02/03/04/11, AUTH-03): sửa luôn KDS-03 (phiên không đóng được). *(P1/P2)*
3. **Giao hàng thất bại và khóa phương thức thanh toán** (DLV-04, DLV-10, DLV-03/06): gỡ hai trạng thái kẹt. *(P2)*
4. **Vòng đời lượt đặt**: giữ chỗ khi trễ / no-show / nhận sớm / hết hạn `PENDING`, tách 4 khoảng thời gian, khóa lượt đã nhận bàn (RSV-03/04/06/07/15/19). *(P3)*
5. **Nhận khách sang bàn khác và không cho đặt bàn đang có người** (RSV-14, RSV-17, kèm SES-05 chuyển bàn trong phiên). *(P2/P3)*
6. **Sửa nhanh MNU-01** (ẩn danh mục thì món ngừng bán) và **RSV-18** (tách ghi chú nội bộ). *(P2/P4 — phạm vi nhỏ)*
7. **Nhật ký thao tác** cho hoàn tiền, thu tiền, hủy món/đơn/lượt đặt (AUD-01) — nên làm cùng nhóm 1 và 2.

Các case P4 (SES-04, KDS-05, DLV-07, MNU-03…) có thể xử lý thủ công trong vận hành và để sau.
