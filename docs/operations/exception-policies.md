# Chính sách vận hành ngoại lệ, cấu hình, lịch chạy và hướng dẫn demo

> Chốt ngày 2026-10-21 (người dùng phê duyệt). Mọi con số nằm ở `config/restaurant.php`, đổi qua `.env`
> khi cần. Múi giờ `Asia/Ho_Chi_Minh`. Kiến trúc: ADR-023 (sổ tiền), ADR-024 (sửa món, duyệt, nhật ký),
> ADR-025 (đặt bàn, phiên), ADR-026 (giao/tự lấy), ADR-027 (thực đơn, giờ, quyền).

## 1. Giờ hoạt động

| Khóa | Mặc định | Ý nghĩa |
|---|---|---|
| `hours.opens_at` / `RESTAURANT_OPENS_AT` | 10:00 | Mở cửa |
| `hours.closes_at` | 22:30 | Đóng cửa |
| `hours.last_reservation_at` | 21:30 | Giờ khách đến muộn nhất của một lượt đặt |
| `hours.last_order_at` | 22:00 | Gọi món mới (POS gửi bếp, QR, đơn online giao/lấy ngay) muộn nhất |

- Thời điểm **gửi yêu cầu** đặt bàn/đặt hẹn không bị giới hạn; chỉ **giờ khách đến/nhận** bị giới hạn.
- Sau 22:00 phiên đang mở vẫn lập hóa đơn, thu tiền, đóng phiên, hủy/sửa món bình thường; không mở phiên mới.

## 2. Đặt bàn & nhận khách

| Khóa | Mặc định | Ý nghĩa |
|---|---|---|
| `reservations.dining_minutes_small_party` | 90 | Thời lượng dùng bàn cho ≤ 4 khách |
| `reservations.dining_minutes_large_party` | 120 | Thời lượng cho > 4 khách |
| `reservations.cleaning_buffer_minutes` | 15 | Dọn bàn giữa hai lượt |
| `reservations.early_seating_minutes` | 30 | Nhận khách sớm tối đa trước giờ hẹn |
| `reservations.grace_minutes` | 20 | Giữ bàn khi khách trễ |
| `reservations.max_extension_minutes` | 30 | Gia hạn cộng dồn tối đa sau hạn giữ gốc |
| `reservations.auto_no_show_after_minutes` | 60 | Sau hạn giữ cuối cùng → tự `NO_SHOW` |
| `reservations.max_active_per_account` | 3 | Lượt tương lai còn hiệu lực tối đa mỗi tài khoản |
| `reservations.overdue_session_block_minutes` | 60 | Phiên quá giờ dự kiến: coi như còn chiếm bàn thêm N phút |
| `reservations.min_walk_in_minutes` | 30 | Khách vãng lai phải còn ít nhất N phút trước lượt đặt kế tiếp |
| `reservations.advance_notice_minutes` / `special_request_notice_minutes` | 30 / 120 | Báo trước khi khách tự đặt |
| `reservations.customer_modify_cutoff_minutes` | 60 | Khách tự sửa/hủy trước giờ hẹn tối thiểu |

Quy tắc:
- Lượt đặt chiếm `[giờ hẹn, giờ hẹn + thời lượng)`; hai lượt trên cùng bàn phải cách nhau ≥ 15 phút dọn.
- `PENDING` hết hạn muộn nhất tại giờ hẹn (API xác nhận bị từ chối, không chặn bàn, hiện "Hết hạn" ngay).
- Ví dụ lượt 19:00: nhận khách 18:30–19:20. Lúc 19:20 chưa đến → POS cảnh báo; nhân viên liên hệ, gia hạn
  (≤ 19:50, có lý do, không chạm lượt kế tiếp, không chạm phiên khách đang ngồi bàn đó — nếu bàn đã nhường cho
  khách vãng lai thì xếp khách đặt sang bàn khác) hoặc ghi "Không đến". 20:20 (hoặc 60 phút sau hạn đã gia hạn)
  lượt tự thành `NO_SHOW`. Hạn giữ đã qua thì bàn không còn bị chặn, kể cả khi lệnh quét chạy trễ.
- **Nhường bàn** sau hạn giữ: "Nhường bàn · mở phiên mới" ghi lượt cũ `NO_SHOW` và mở phiên mới trong cùng
  giao dịch. Khách đến muộn sau đó được xếp như khách vãng lai trên bàn trống.
- Nhận khách ngoài khung: STAFF nhận 403 và gửi yêu cầu duyệt `SEAT_OUTSIDE_WINDOW`; ADMIN nhận trực tiếp
  kèm lý do hoặc duyệt yêu cầu (phiên mở tự động khi duyệt).
- Nhận khách ở bàn khác bàn đã đặt: cần lý do + kiểm tra trùng. Vượt sức chứa: ghép thêm bàn.
- Số khách thực tế lưu ở phiên; số đặt giữ ở lượt đặt.
- Khách không sửa/hủy được lượt đã nhận bàn; `COMPLETED` chỉ khi đóng phiên.
- Ghi chú nội bộ (`ghi_chu_noi_bo`) không bao giờ trả cho khách và không tham gia luật "yêu cầu đặc biệt".
- Nhà hàng hủy/từ chối bắt buộc lý do (khách thấy lý do).

## 3. Phiên phục vụ & bàn

- STAFF đổi số khách (trong sức chứa), chuyển bàn, ghép bàn; phiên, món, hóa đơn, lịch sử giữ nguyên.
- Bàn rời đi: `CLEANING` (đã dùng, mặc định) / `AVAILABLE` (chưa dùng, mở nhầm) / `MAINTENANCE` (sự cố).
- Bàn mới phải trống, không thuộc phiên khác, không trùng lượt đặt trong thời gian phiên còn chiếm.
- Thẻ QR của khách đi theo phiên: QR bàn cũ báo "đã chuyển sang Bàn X", thẻ dùng được ở bàn mới. Sau khi phiên
  đóng, thẻ vẫn đọc được hóa đơn ở bàn mới trong `dining.visit_readable_hours`, nhưng không vào bữa mới của bàn.
- **Kết thúc phiên không tính tiền** (`finish-without-charge`, STAFF/ADMIN, lý do bắt buộc): chỉ khi mọi món đã
  xuống bếp đều bị hủy/từ chối với 0 phần tính tiền, không có món nháp, không có hóa đơn còn hiệu lực và chưa nhận
  tiền. Phiên `CLOSED` kiểu `NO_CHARGE`, bàn chờ vệ sinh, lượt đặt (nếu có) hoàn thành, nhật ký
  `SESSION_CLOSED_NO_CHARGE`. Phiên chưa có món nào xuống bếp vẫn dùng "Hủy phiên"; còn món tính tiền thì lập hóa đơn.
- Khách bỏ về: chỉ ADMIN ghi thất thu (trực tiếp hoặc duyệt yêu cầu `SESSION_WRITE_OFF` của STAFF) với lý do;
  hóa đơn `WRITTEN_OFF` (không bao giờ PAID), tiền đã nhận giữ nguyên, báo cáo thất thu riêng.

## 4. Món & bếp

| Trạng thái món | Hủy | Tính tiền |
|---|---|---|
| `DRAFT` | STAFF xóa dòng | — |
| `SENT`, `QUEUED` | STAFF, lý do bắt buộc | Không tính |
| `PREPARING`, `READY`, `SERVED` | Cần ADMIN (STAFF gửi yêu cầu `ITEM_VOID`) | Lỗi nhà hàng: không tính. Khách đổi ý: vẫn tính trừ khi ADMIN miễn |

- Bếp từ chối món (`OUT_OF_STOCK` / `RESTAURANT_MISTAKE` / `DAMAGED`) khi SENT/QUEUED/PREPARING; tùy chọn báo hết món.
  POS, khách QR, tổng tiền, hóa đơn cập nhật; phiên không bị kẹt (mọi món bị từ chối → "Kết thúc phiên không tính
  tiền", §3).
- Làm lại: dòng `REMAKE` giá 0 liên kết dòng gốc (không tính vào món bán chạy theo số phần bán; báo cáo hiện riêng
  số phần bếp làm và số phần miễn phí). Đổi món: hủy dòng cũ (giữ snapshot) + dòng nháp mới liên kết.
- Tách dòng: chia số phần, giữ tổng. Hoàn tác bước bếp trong `kitchen.undo_window_seconds` = 120 giây nếu
  chưa có bước phụ thuộc.
- Hóa đơn chưa thanh toán: thay đổi món → bản cũ VOID, lập bản mới (`HD{id}V{n}`). Đã thanh toán: hóa đơn giữ
  PAID, phần dư hiện "cần hoàn" và được ghi nhận hoàn ở mục Thanh toán.

## 5. Giao hàng & tự lấy

| Khóa | Mặc định | Ý nghĩa |
|---|---|---|
| `fulfillment.scheduled_prep_lead_minutes` | 30 | Đơn hẹn xuống bếp từ giờ hẹn − N (API bếp từ chối 409 trước mốc này; quản lý cho làm sớm ở màn Đơn hàng phải có lý do, nhật ký `ORDER_STARTED_EARLY`) |
| `fulfillment.max_schedule_days` | 7 | Hẹn tối đa N ngày tới |
| `fulfillment.pickup_overdue_minutes` | 60 | Giữ món tự lấy sau max(giờ sẵn sàng, giờ hẹn) |
| `fulfillment.delivery_handoff_warning_minutes` | 20 | Cảnh báo món xong chờ tài xế |

- Phương thức thanh toán online bị khóa từ khi đơn được xác nhận; tạo/lặp QR không âm thầm đổi CASH→QR.
- Sửa người nhận/SĐT/địa chỉ (khách hoặc admin) đến trước khi giao tài xế/bàn giao, có lịch sử.
- Giao thất bại: chọn nguyên nhân + mô tả → giao lại hoặc hủy. Tiền mặt chỉ được ghi nhận khi bàn giao thành công.
- Hủy đơn: tiền đã thu mặc định "phải hoàn"; "giữ tiền" chỉ khi khách không đến lấy quá hạn hoặc khách
  từ chối/không liên lạc được khi giao, do ADMIN quyết định. Địa chỉ ngoài phạm vi → luôn phải hoàn.
- Không thu phí giao hàng chưa được xác nhận (phí = 0).
- Tự lấy: mã nhận hàng 4 số (khách thấy trên đơn); bàn giao khi đúng mã hoặc đúng SĐT đặt đơn; ghi người lấy hộ.

## 6. Tiền (sổ giao dịch)

| Khóa | Mặc định | Ý nghĩa |
|---|---|---|
| `payments.tolerance` / `PAYMENT_TOLERANCE` | 0 | Dung sai chênh lệch |
| `payments.min_reference_length` | 6 | Mã giao dịch nhập tay tối thiểu |
| `payments.default_provider` | VIETQR | Nhà cung cấp mặc định (tiền mặt/POS) |
| `payments.generic_providers` | VIETQR, NAPAS, QR, MANUAL, POS, WEBHOOK, LEGACY… | Nhãn nguồn không phải tên ngân hàng — không dùng để định danh khoản chuyển |
| `payments.bank_aliases` | VCB, MB, TCB, ACB, BIDV, VTB, TPB, VPB, STB, VBA | Chuẩn hóa tên/BIN ngân hàng về một mã |

- Mỗi khoản tiền thật = một dòng `GIAO_DICH_TIEN`. Chuyển khoản định danh bằng `BANK:{ngân hàng}|{tài khoản nhận}|{mã}`:
  tài khoản nhận đã cấu hình (`VIETQR_ACCOUNT_NO`/`VIETQR_BANK_BIN`) luôn quy về ngân hàng đã cấu hình, dù xác nhận
  tay, POS hay webhook ghi nhãn nguồn nào → cùng một khoản chỉ ghi một lần. Ngân hàng do nguồn nêu rõ luôn được dùng:
  cùng dãy số tài khoản ở ngân hàng khác là khoản khác (ghi riêng, chuyển đối soát vì thông tin mâu thuẫn). Cùng mã ở
  ngân hàng/tài khoản khác là khoản khác; nếu một bên thiếu ngân hàng hoặc tài khoản thì ghi riêng và chuyển đối soát
  thay vì gộp đoán. Webhook trỏ tới đơn/hóa đơn không tồn tại vẫn được lưu (`UNKNOWN_TARGET`, cần xem xét).
- Quản lý ghi nhận thêm một khoản chuyển (mã khác) cho đơn đã đủ tiền: được ghi, cờ `DUPLICATE`, chờ hoàn.
- Thiếu → thanh toán một phần + thu bù. Dư → áp đủ, phần dư "chưa phân bổ" chờ hoàn. Lần chuyển thứ hai → cờ
  `DUPLICATE`, không tính doanh thu. Tiền cho đơn đã hủy/hóa đơn VOID/QR cũ/phiên đã đóng → lưu để xem xét.
- Hoàn tiền chỉ là **ghi nhận** (ADMIN, lý do, chứng từ, phương thức, thời điểm); không vượt đã nhận − đã hoàn.
- Hai loại: **hoàn** (`REFUND`) trả phần nhà hàng đang nợ khách (tiền dư, đơn hủy, món hủy sau thanh toán);
  **bồi hoàn** (`COMPENSATION`) trả lại một phần cho đơn vẫn hiệu lực → giảm khoản phải thu (`so_tien_giam_tru`),
  hóa đơn giữ PAID, đơn không thành "còn thiếu", báo cáo trừ khỏi giá trị bán (`compensationValue`). Hoàn vượt
  phần đang nợ trên đơn còn hiệu lực mà không chọn bồi hoàn → 422.
- Hoàn một khoản đã áp cho nhiều đơn: mỗi đơn có dòng hoàn riêng đúng phần rút từ đơn đó; phần tiền chưa phân bổ là
  dòng riêng. Các dòng của một lần ghi chung khóa yêu cầu.
- Gửi lại an toàn: mỗi lần ghi hoàn có khóa yêu cầu (`Idempotency-Key`) và chứng từ; gửi lại cùng khóa hoặc cùng
  chứng từ với cùng số tiền/hình thức trả lại bản ghi cũ, khác nội dung → 409. Thu tiền POS cũng vậy: cùng khóa
  khác số tiền/hình thức/hóa đơn → 409; máy POS giữ khoản đang gửi để "Gửi lại khoản thu" sau khi mất phản hồi.

## 7. Ma trận quyền

| Thao tác | STAFF | ADMIN |
|---|---|---|
| Nhận khách trong khung, gia hạn, ghi không đến, nhường bàn | ✓ | ✓ |
| Đổi số khách, chuyển/ghép bàn | ✓ | ✓ |
| Hủy món chưa nấu, bếp từ chối, làm lại, đổi, tách, hoàn tác bước bếp | ✓ | ✓ |
| Thu tiền tại POS | ✓ (lưu người thu) | ✓ |
| Kết thúc phiên không tính tiền (mọi món bị từ chối/hủy) | ✓ (lý do) | ✓ |
| Cho đơn hẹn giờ xuống bếp sớm | — | ✓ (lý do) |
| Hủy món đã nấu, miễn tiền | gửi yêu cầu | ✓ |
| Nhận khách ngoài khung giờ | gửi yêu cầu | ✓ (lý do) |
| Thất thu (khách bỏ về) | gửi yêu cầu | ✓ |
| Xác nhận chuyển khoản thủ công đơn online, phân bổ, ghi nhận hoàn tiền, hủy đơn online | — | ✓ |

Quản lý duyệt trên tài khoản của mình (Quản trị → Duyệt yêu cầu, hoặc ngay trong phiên POS khi đăng nhập ADMIN);
không có ô nhập mật khẩu quản lý trên máy nhân viên.

## 8. Lịch chạy (scheduler)

`routes/console.php`: `Schedule::command('dining:reservations:sweep')->everyFiveMinutes()->withoutOverlapping();`

- `PENDING` quá giờ hẹn → `EXPIRED`; `CONFIRMED` chưa nhận bàn quá hạn tự động → `NO_SHOW`; làm mới trạng thái giữ bàn.
- Chạy thủ công: `php artisan dining:reservations:sweep`. Máy dev chạy định kỳ: `php artisan schedule:work`.
  Production: cron `* * * * * php artisan schedule:run`.
- API không phụ thuộc lệnh quét: hạn chờ/giữ chỗ được kiểm tra trực tiếp khi tìm bàn, mở phiên, xác nhận.

## 9. Hướng dẫn demo ngoại lệ

Yêu cầu: Laravel :8000, Vite :5173, chạy trong 11:10–21:30, tài khoản mẫu `staff1`, `admin`, `kh1` (mật khẩu `123`).

Tự động: `node scripts/exception-e2e.cjs` (repo frontend). Dữ liệu tạo ra mang định danh `[E2E-NGOAI-LE]`, chỉ dùng
bàn demo Live Dining (`php artisan dining:demo` làm mới trước và sau), không chạm bàn khác.

Thủ công:
1. **Khách trễ:** tạo lượt đặt đã xác nhận 10 phút trước giờ hiện tại → POS thẻ bàn "Trễ 10 phút" → "Gia hạn giữ bàn"
   (nhập lý do). Khi quá hạn → "Nhường bàn · mở phiên mới".
2. **Hủy món:** trong phiên POS, món đã gửi bếp → "Xử lý" → "Hủy món" (lý do). Món đang nấu → "Gửi quản lý duyệt";
   đăng nhập admin → "Duyệt yêu cầu" → "Duyệt".
3. **Bếp hết nguyên liệu:** màn bếp → "Không làm được" → chọn "Hết nguyên liệu" + "Báo hết món".
4. **Tiền dư:** admin → Thanh toán → "Ghi nhận tiền về" với số tiền lớn hơn đơn → tab "Sổ giao dịch tiền" → khoản "Cần
   xem xét" → "Ghi nhận đã hoàn tiền".
5. **Giao thất bại:** admin → Đơn hàng → đơn đang giao → "Giao thất bại" → "Giao lại".
6. **Tự lấy:** khách xem mã 4 số trong chi tiết đơn; admin "Xác minh & bàn giao" nhập mã hoặc SĐT.
7. **Khách bỏ về:** phiên POS → "Khách bỏ về" (STAFF gửi duyệt, ADMIN ghi thất thu).
