# Ánh xạ 89 case kiểm toán → thay đổi → bằng chứng → giới hạn còn lại

> Milestone "ngoại lệ nghiệp vụ" (2026-10-21). Báo cáo gốc: `docs/reviews/business-edge-case-audit.md`.
> Chính sách đã chốt: `docs/operations/exception-policies.md`. Quyết định kiến trúc: ADR-023 → ADR-027.
>
> **Trạng thái:** ✅ đã xử lý (API + UI + test) · 🟡 đã xử lý phần chính, còn giới hạn ghi ở cột cuối ·
> 🔵 giữ nguyên (đã đạt từ trước, có test) · ⚪ chưa xác minh được trên môi trường hiện có (E).
>
> **Bằng chứng:** tên test trong `tests/Feature/*` (chạy `php artisan test --compact`), E2E trình duyệt trong
> `restaurant-fe/scripts/*.cjs`. Viết tắt file test: **LED** `PaymentLedgerApiTest`, **ITM** `ItemCorrectionApiTest`,
> **DLV** `DeliveryExceptionApiTest`, **RSV** `ReservationLifecycleApiTest`, **SES** `SessionChangeAndMenuRulesApiTest`,
> **POS** `PosReservationAndBillingApiTest`, **AUD** `AuditAndSchedulerTest`, **LIVE** `LiveDiningApiTest`,
> **WH** `PaymentWebhookApiTest`, **ADM** `AdminOperationsApiTest`, **CRS** `CustomerReservationApiTest`,
> **PRV** `PaymentReviewRegressionTest`, **ORV** `OperationsReviewRegressionTest` (nghiệm thu review A–N).
> E2E: **EX** `scripts/exception-e2e.cjs`, **LD** `scripts/live-dining-e2e.cjs`, **API** `scripts/e2e-api-flow.cjs`,
> **OPS** `scripts/operations-smoke.cjs`, **RR** `scripts/review-regression-e2e.cjs`.
>
> Giới hạn chung (áp cho mọi case dùng khóa dòng): test chạy trên SQLite in-memory; SQLite khóa toàn DB và
> `php artisan serve` đơn luồng, nên **không có bằng chứng về khóa dòng/đồng thời thật trên MySQL/SQL Server**.

## Tổng kết

| Nhóm | Số case | ✅ | 🟡 | 🔵 | ⚪ |
|---|---|---|---|---|---|
| RSV | 19 | 17 | 1 | 1 | 0 |
| SES | 11 | 7 | 0 | 3 | 1 |
| QR | 12 | 2 | 3 | 7 | 0 |
| KDS | 12 | 8 | 1 | 3 | 0 |
| DLV | 10 | 10 | 0 | 0 | 0 |
| PAY | 17 | 12 | 1 | 2 | 2 |
| MNU/AUTH/AUD | 8 | 5 | 2 | 1 | 0 |
| **Tổng** | **89** | **61** | **8** | **17** | **3** |

> **Đính chính sau review (A–N, 2026-10-21):** 10 dòng trước đây ghi ✅ nhưng còn lỗi thật khi xác minh lại:
> RSV-05 (I), SES-05 (J), KDS-03 (E), KDS-04/PAY-16 (M), KDS-12 (L), PAY-05 (D), PAY-07 (H), PAY-08 (A, B),
> PAY-14 (C, F). Các dòng này đã được viết lại theo hành vi hiện tại; trạng thái chỉ giữ ✅ khi có test nghiệm thu
> chạy trên suite chính. Chi tiết từng phát hiện ở mục cuối "Review A–N".

## Đặt bàn & nhận khách (RSV)

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| RSV-01 Đặt trùng bàn/giờ | A | Kiểm tra trùng theo khoảng dùng bàn thật + 15 phút dọn (`ReservationSchedule::conflictingReservations`), vẫn khóa `BAN_AN`. | RSV `test_availability_uses_real_dining_intervals_and_the_cleaning_buffer`; CRS | Đồng thời thật chưa kiểm trên DB production. 🔵 |
| RSV-02 Một khách giữ nhiều bàn | C | Tối đa 3 lượt tương lai còn hiệu lực/tài khoản; không cho hai lượt của cùng khách chồng giờ (khóa dòng tài khoản khi tạo). | RSV `test_an_account_cannot_hold_more_than_three_reservations_or_overlap_its_own` | ✅ |
| RSV-03 PENDING bị bỏ quên | C | `EXPIRED`; API xem PENDING quá giờ là hết hạn ngay (`effectiveStatus`, không chặn bàn, không xác nhận được); lệnh `dining:reservations:sweep` (5 phút/lần) ghi trạng thái. | RSV `test_a_pending_request_expires_at_its_time_even_before_the_sweep_runs`; AUD `test_the_reservation_sweep_is_scheduled_without_overlap` | Chưa gửi thông báo chủ động cho khách (khách thấy trạng thái khi mở trang). ✅ |
| RSV-04 Khách trễ | B | Giữ bàn 20 phút; POS hiện "Trễ N phút · giữ bàn đến HH:mm", bộ lọc "Khách trễ / quá giờ"; quá hạn thì không còn chặn khách vãng lai. | RSV `test_late_guests_get_a_grace_period_…`, `test_an_expired_hold_stops_blocking_walk_ins_even_if_the_sweep_is_late`; EX bước 2 | ✅ |
| RSV-05 Khách báo trễ | C | `POST /staff/pos/reservations/{id}/extend` (STAFF): cộng dồn ≤ +30 phút sau hạn gốc, lý do bắt buộc, kiểm tra không chạm lượt kế tiếp, lưu người/giờ. **Sửa sau review (I):** gia hạn bị từ chối (409) khi bàn đã có phiên khách khác chồng khoảng giữ mới. | RSV `test_late_guests_…`, `test_an_extension_that_would_hit_the_next_booking_is_refused`; ORV `test_i_a_hold_cannot_be_extended_after_the_table_was_given_to_walk_in_guests`; EX bước 2 | ✅ |
| RSV-06 No-show | C | `NO_SHOW` (STAFF đánh dấu sau hạn giữ chỗ; tự động sau 60 phút); `COMPLETED` chỉ đến từ đóng phiên (admin bấm tay → 409). | RSV `test_the_desk_sweep_marks_no_shows_only_after_the_auto_deadline`, `test_actual_guest_count_…` (COMPLETED 409); EX bước 3 | ✅ |
| RSV-07 Khách đến rất sớm | D | API kiểm cửa sổ nhận khách [giờ hẹn − 30, hạn giữ chỗ]; ngoài cửa sổ: STAFF 403 → gửi yêu cầu duyệt, ADMIN nhận kèm lý do. | RSV `test_seating_outside_the_window_needs_a_manager`; API (staff 403, admin override) | ✅ |
| RSV-08 Đến sau khi bàn đã nhường | C | Theo chính sách: lượt đã `NO_SHOW` không nhận lại; khách được xếp như khách vãng lai trên bàn trống. Nhường bàn = ghi no-show + mở phiên mới trong một giao dịch. | RSV `test_giving_away_an_expired_hold_and_seating_new_guests_happens_atomically`; EX bước 3 | Phiên mới không liên kết lượt đặt cũ (đúng chính sách). ✅ |
| RSV-09 Số khách khác số đặt | A | Số khách thực tế lưu ở phiên, số đặt giữ nguyên ở lượt đặt; admin thấy `actualGuestCount`. | RSV `test_actual_guest_count_is_kept_apart_from_the_booking_…` | ✅ |
| RSV-10 Vượt sức chứa | B | API ghép nhiều bàn khi nhận khách; UI "Ghép thêm bàn" trong modal nhận khách; vượt sức chứa → 422 "ghép thêm bàn". | RSV `test_actual_guest_count_…` | ✅ |
| RSV-11 Đổi/hủy sát giờ | B | Khách vẫn bị khóa trong 60 phút trước giờ hẹn; nhà hàng sửa giờ/bàn/số khách qua `PUT /admin/reservations/{id}` (lý do, kiểm tra trùng, lịch sử). | RSV `test_the_restaurant_can_move_a_booking_to_another_table_with_conflict_checks` | Không có chính sách cọc/phạt hủy sát giờ (ngoài phạm vi). ✅ |
| RSV-12 Nhà hàng hủy vì sự cố | B | Hủy/từ chối bắt buộc lý do ở API (khách thấy `statusReason`); chuyển lượt sang bàn khác bằng sửa lượt đặt. | RSV `test_internal_notes_stay_internal_and_restaurant_cancellations_need_a_reason`, `test_the_restaurant_can_move_a_booking_…` | Không gửi thông báo (SMS/email) cho khách. ✅ |
| RSV-13 Ngồi quá lâu | C | Phiên có `du_kien_ket_thuc_luc` (90/120 phút, bị cắt trước lượt kế tiếp − 15 phút); thẻ bàn cảnh báo "Quá giờ trả bàn" / lượt kế tiếp bị ảnh hưởng. | POS `test_walk_in_before_a_later_reservation_is_told_when_to_leave` | Cảnh báo hiển thị trên POS, chưa có thông báo đẩy. ✅ |
| RSV-14 Nhận khách sang bàn khác | D | `relocationReason` khi mở phiên với bàn khác bàn đặt; kiểm tra trùng trên bàn mới; ghi nhật ký. | POS `test_reservation_for_another_table_needs_a_relocation_reason` | `DAT_BAN.ma_ban` giữ bàn đã đặt; bàn thực tế lấy từ phiên. ✅ |
| RSV-15 Sửa/hủy sau khi đã nhận bàn | D | Khách không sửa/hủy được khi lượt đã gắn phiên; `canModify=false`, UI báo "đã nhận bàn". | RSV `test_actual_guest_count_…` (khách hủy → 409) | ✅ |
| RSV-16 Ngoài giờ mở cửa | C | Giờ khách đến ∈ [10:00, 21:30] (tìm bàn + tạo + sửa); thời điểm gửi yêu cầu không bị giới hạn. Cấu hình `config/restaurant.php`. | RSV `test_arrival_must_fall_inside_reservation_hours_while_the_request_can_be_sent_at_night` | Giờ mở cửa giống nhau mọi ngày (chưa có lịch theo thứ/ngày lễ). 🟡 |
| RSV-17 Đặt bàn đang có khách | D | Tìm bàn/tạo lượt loại bàn có phiên chiếm khoảng thời gian (giờ dự kiến kết thúc; quá giờ coi như chiếm thêm 60 phút). | RSV `test_a_table_with_guests_seated_now_is_not_offered_until_their_expected_end`; POS `test_walk_in_…` | ✅ |
| RSV-18 Ghi chú nội bộ lộ cho khách | D | Cột `ghi_chu_noi_bo` riêng (migration chuyển dòng `[ADMIN]` cũ sang); khách không nhận; luật "yêu cầu đặc biệt" chỉ đọc ghi chú của khách. | RSV `test_internal_notes_stay_internal_…` | ✅ |
| RSV-19 Hằng 119 phút | D | Bỏ `SLOT_MINUTES`; tách cấu hình: thời lượng 90/120, dọn 15, nhận sớm 30, giữ chỗ 20, gia hạn 30, no-show 60. | RSV (toàn bộ); `config/restaurant.php` | ✅ |

## Phiên phục vụ & bàn (SES)

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| SES-01 Mở trùng phiên | A | Giữ nguyên (idempotency + khóa bàn). | `PosFloorApiTest`, LIVE | 🔵 |
| SES-02 Mở nhầm bàn | B | Sau khi gửi bếp: chuyển phiên sang bàn đúng (`POST /staff/pos/sessions/{id}/tables`, bàn cũ chưa dùng → AVAILABLE). | SES `test_moving_a_session_keeps_its_items_…` | ✅ |
| SES-03 Số khách thực tế | A | Như RSV-09. | RSV | 🔵 |
| SES-04 Thêm/bớt khách | C | `PATCH /staff/pos/sessions/{id}/guest-count` (trong sức chứa, ghi nhật ký, tính lại giờ trả bàn). | SES `test_guest_count_changes_within_capacity_and_is_audited` | ✅ |
| SES-05 Đổi bàn | C | Chuyển bàn giữ phiên/món/hóa đơn/lịch sử; bàn cũ CLEANING/AVAILABLE/MAINTENANCE; thẻ QR của khách dùng tiếp ở bàn mới, QR bàn cũ báo "đã chuyển". **Sửa sau review (J):** sau khi phiên đóng, thẻ vẫn đọc được hóa đơn ở bàn mới trong `dining.visit_readable_hours`, không vào được bữa mới. | SES `test_moving_…`, `test_the_guest_pass_works_on_the_new_table_after_a_move`; ORV `test_j_a_moved_guest_can_still_read_the_bill_at_the_new_table_after_closing_but_not_a_new_meal` | ✅ |
| SES-06 Ghép/tách bàn | B | Ghép thêm bàn giữa phiên (kiểm tra lượt đặt, sức chứa); tách = chuyển phiên sang tập bàn nhỏ hơn. | SES `test_tables_can_be_merged_but_not_onto_a_table_held_for_a_reservation` | Không tách một phiên thành hai hóa đơn. ✅ |
| SES-07 Bàn chờ vệ sinh | A | Giữ nguyên. | `PosFloorApiTest` | 🔵 |
| SES-08 Bàn sự cố giữa phiên | C | Chuyển bàn với `releaseAs=MAINTENANCE`, lý do bắt buộc. | SES `test_the_guest_pass_works_on_the_new_table_after_a_move` | ✅ |
| SES-09 Khách bỏ về | C | Thất thu chỉ ADMIN (trực tiếp hoặc duyệt yêu cầu của STAFF): lý do, người duyệt, số thất thu; hóa đơn `WRITTEN_OFF`, không bao giờ ghi PAID, giữ tiền đã nhận; báo cáo `writeOffValue`. | ITM `test_guest_who_walks_out_is_written_off_only_by_an_admin_…`, `test_write_off_rolls_back_completely_when_nothing_is_owed` | ✅ |
| SES-10 Hai nhân viên cùng thao tác | A/E | Giữ nguyên khóa/version; thêm version cho đổi số khách/bàn. | `PosWorkflowApiTest` | ⚪ Đồng thời thật chưa kiểm. |
| SES-11 Phiên qua ngày | E | Báo cáo tiền theo ngày tiền về/ngày hoàn (không theo ngày đặt). | LED `test_report_counts_money_by_the_day_it_was_received_…` | ✅ (giá trị bán vẫn theo ngày đặt đơn, đã ghi rõ trong công thức). |

## QR tại bàn (QR)

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| QR-01 Link/token cũ | A | Giữ nguyên; thẻ khách chỉ dùng được ở bàn quét hoặc bàn phiên được chuyển sang. | LIVE | 🔵 |
| QR-02 Tạo lại mã bàn | A | Giữ nguyên. | LIVE | 🔵 |
| QR-02b Tạo lại mã QR khi đang có khách | B | Không đổi. | LIVE `test_rotating_the_qr_token_revokes_the_printed_code` | Admin chưa thấy cảnh báo "bàn đang có N thiết bị" trước khi tạo lại. 🟡 |
| QR-03 Thiết bị mới/storage bị chặn | B | Không đổi (bị chặn storage → nhắc gọi nhân viên). | LD | Giữ nguyên giới hạn của trình duyệt. 🟡 |
| QR-04 Nhiều thiết bị | A | Giữ nguyên. | LIVE | 🔵 |
| QR-05 Double click/reload | A | Giữ nguyên; gửi lại lượt cũ vẫn được xác nhận kể cả khi giá vừa đổi. | SES `test_a_qr_round_with_stale_prices_…`; LD bước 8 | 🔵 |
| QR-06 Sửa giỏ khi đang gửi | A | Giữ nguyên. | LD | 🔵 |
| QR-07 Món đổi giá/hết bán | A/B | Gửi kèm `expectedTotal`; lệch → 409 `PRICE_CHANGED` + giá mới, món trả về giỏ với giá mới; ngoài giờ gọi món → 409. | SES `test_a_qr_round_with_stale_prices_…`, `test_new_orders_stop_at_last_order_time_…` | ✅ |
| QR-08 Phiên đổi trạng thái khi đang gửi | A | Giữ nguyên. | LIVE | 🔵 |
| QR-09 Yêu cầu phục vụ trùng | A | Giữ nguyên. | LIVE | 🔵 |
| QR-10 Xin thanh toán nhưng muốn gọi thêm | B | Không đổi luồng khách; nhân viên "Gọi thêm món" (hủy bản hóa đơn, QR cũ hết hiệu lực, tiền đã trả giữ nguyên). | POS `test_reopening_…`; LED `test_old_invoice_qr_after_reissue_…` | Khách vẫn không tự hủy được yêu cầu thanh toán đã được nhận. 🟡 |
| QR-11 Lộ dữ liệu phiên khác | A | Giữ nguyên; trạng thái khách chỉ nhận nhãn lý do hủy món, không ghi chú nội bộ. | LIVE; LD bước 20 | ✅ |

## POS & bếp (KDS)

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| KDS-01 Gọi nhầm sau khi gửi | C | `POST /staff/pos/order-items/{id}/void`: SENT/QUEUED STAFF hủy có lý do, không tính tiền; hóa đơn mở được lập lại phiên bản mới. | ITM `test_staff_cancels_an_item_before_cooking_…`; EX bước 4 | ✅ |
| KDS-02 Hủy khi đang nấu/đã phục vụ | C | PREPARING/READY/SERVED: STAFF 403 → yêu cầu duyệt `ITEM_VOID`; ADMIN duyệt; lỗi nhà hàng không tính tiền; khách đổi ý vẫn tính trừ khi ADMIN miễn. | ITM `test_staff_cannot_cancel_a_cooked_item_…`, `test_admin_approval_…`, `test_admin_can_waive_…`, `test_restaurant_fault_…`; EX bước 5–6 | ✅ |
| KDS-03 Hết nguyên liệu | D | `POST /staff/kitchen/items/{id}/reject` (lý do, tùy chọn báo hết món); trừ tiền, POS/khách/hóa đơn đồng bộ. **Sửa sau review (E):** trước đây phiên mà *mọi* món đều bị từ chối/hủy không đóng được (không lập hóa đơn 0 ₫, không hủy phiên, không thất thu); nay có `POST /staff/pos/sessions/{id}/finish-without-charge` (lý do, chỉ khi không còn món tính tiền, không có tiền nhận, không có hóa đơn còn hiệu lực) → phiên `CLOSED`/`NO_CHARGE`, bàn chờ vệ sinh, nhật ký. | ITM `test_kitchen_rejection_frees_the_session_and_can_mark_the_dish_sold_out`; ORV `test_e_a_session_whose_items_were_all_rejected_can_be_finished_without_charge`, `test_e_finishing_without_charge_is_refused_while_anything_is_still_charged` | ✅ |
| KDS-04 Làm lại / đổi món | C | `remake` tạo dòng REMAKE giá 0 liên kết dòng gốc, phiếu bếp mới; `exchange` hủy dòng cũ (giữ snapshot) + dòng nháp mới liên kết. **Sửa sau review (M):** món bán chạy đếm số phần *tính tiền* và doanh thu theo `billedAmount`; phần làm lại/miễn tách thành `freeQuantity`, phần bếp làm thật là `preparedQuantity`. | ITM `test_a_remake_is_free_…`, `test_exchange_keeps_the_old_snapshot_…`; ORV `test_m_top_selling_counts_charged_portions_and_keeps_free_remakes_apart` | ✅ |
| KDS-05 Phục vụ một phần | C | `split` tách dòng, tổng tiền/số phần giữ nguyên. | ITM `test_splitting_a_line_preserves_quantity_and_amount` | ✅ |
| KDS-06 Một món trễ | A/B | Giữ SLA theo món. | `PosWorkflowApiTest` | Chưa có cảnh báo chủ động riêng cho quản lý. 🟡 |
| KDS-07 Bấm sai trạng thái | B | Hoàn tác bước gần nhất trong 120 giây nếu chưa có bước phụ thuộc; ghi người thao tác. | ITM `test_a_kitchen_step_can_be_undone_only_within_the_window` | ✅ |
| KDS-08 Hai người cùng chuyển | A | Giữ nguyên. | `PosWorkflowApiTest` | 🔵 |
| KDS-09 Gọi thêm sau hóa đơn | A | Giữ nguyên; hóa đơn có phiên bản. | POS | 🔵 |
| KDS-10 Chốt tiền khi còn món nấu | A | Giữ nguyên; món bị từ chối/hủy không chặn đóng phiên. | ITM `test_kitchen_rejection_…` | 🔵 |
| KDS-11 Hủy món ≠ xóa nháp ≠ hoàn tiền | C | Ba thao tác tách biệt: xóa dòng nháp, hủy món (lý do, số phần tính tiền), ghi nhận hoàn tiền (sổ cái). Sau thanh toán: hủy món → hóa đơn giữ PAID, hiện "cần hoàn". | ITM `test_voiding_after_payment_leaves_the_paid_invoice_and_shows_a_refund_due` | ✅ |
| KDS-12 Đơn hẹn giờ xuống bếp ngay | C | KDS chỉ hiện đơn hẹn từ giờ hẹn − 30 phút; danh sách "Đơn hẹn giờ sắp tới"; giờ hẹn trong giờ nhận đơn, ≤ 7 ngày. **Sửa sau review (L):** API bếp trước đây vẫn cho chuyển món của đơn hẹn sang Đang làm trước giờ xuống bếp; nay 409. Quản lý cho làm sớm ở màn Đơn hàng phải nhập lý do (422 nếu thiếu), ghi nhật ký `ORDER_STARTED_EARLY`. | DLV `test_scheduled_times_must_fall_inside_ordering_hours`; ORV `test_l_the_kitchen_api_cannot_start_a_scheduled_order_early_but_a_manager_can_with_a_reason` | ✅ |

## Giao hàng & tự lấy (DLV)

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| DLV-01 Sửa địa chỉ/SĐT | C | Khách và admin sửa người nhận/SĐT/địa chỉ đến trước khi giao tài xế; lịch sử trước/sau. | DLV `test_the_customer_can_fix_the_address_until_the_driver_leaves` | ✅ |
| DLV-02 Ngoài phạm vi | C | Hủy với `reasonType=ADDRESS_UNSERVICEABLE`, tiền đã thu bắt buộc "phải hoàn". Phí giao = 0 (không thu phí chưa được xác nhận). | DLV `test_an_unserviceable_address_is_cancelled_with_a_refund_due` | Không có bảng vùng/phí giao (chưa chốt) — nhân viên quyết định. ✅ |
| DLV-03 Không có tài xế | B | Cảnh báo "chờ tài xế quá 20 phút" trên đơn và dashboard; hủy có lý do. | DLV `test_a_driver_waiting_too_long_is_flagged` | ✅ |
| DLV-04 Giao thất bại | D | `DELIVERY_FAILED` (nguyên nhân + mô tả) → giao lại hoặc hủy; tiền mặt không ghi đã thu; giữ tiền chỉ khi lỗi do khách. | DLV `test_a_failed_cash_delivery_…`, `test_an_order_on_the_road_…`, `test_money_is_kept_only_when_…`; EX bước 8 | ✅ |
| DLV-05 Hủy khi đã nấu/đang giao | B | Đang giao: phải ghi kết quả giao trước; sau đó hủy với quyết định tiền. | DLV `test_an_order_on_the_road_cannot_be_cancelled_until_the_failure_is_recorded` | ✅ |
| DLV-06 Tự lấy sớm/muộn/không đến | B | Giữ món 60 phút sau max(giờ sẵn sàng, giờ hẹn) → cảnh báo; hủy `PICKUP_NO_SHOW`, ADMIN chọn hoàn/giữ tiền. | DLV `test_an_uncollected_pickup_becomes_overdue_and_can_be_closed_as_a_no_show` | ✅ |
| DLV-07 Người lấy hộ | C | Mã nhận hàng 4 số (chỉ khách thấy); bàn giao khi đúng mã hoặc đúng SĐT đặt đơn; ghi tên người lấy hộ. | DLV `test_pickup_is_handed_over_only_after_the_code_or_phone_matches`; API | ✅ |
| DLV-08 Xong món chưa bàn giao | B | `handoff.waitMinutes`, cảnh báo tài xế/khách quá hạn; dashboard `driverWaitTooLong`, `pickupOverdue`. | DLV `test_a_driver_waiting_too_long_is_flagged` | ✅ |
| DLV-09 Giao thiếu/sai món | C | Ghi nhận hoàn **một phần** theo số tiền, chứng từ, lý do (sổ cái). | LED `test_partial_refunds_cannot_exceed_what_was_received` | Chưa có khiếu nại theo từng dòng món cho đơn online. ✅ |
| DLV-10 Đổi tiền mặt → QR khi đang giao | D | Khóa phương thức online từ khi đơn được xác nhận (409); tạo/lặp QR không âm thầm đổi CASH→QR. | DLV `test_a_cash_order_cannot_switch_to_qr_once_it_is_on_the_road` | ✅ |

## Hóa đơn, thanh toán, hoàn tiền (PAY)

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| PAY-01 Lập hóa đơn hai lần | A | Giữ nguyên. | POS | 🔵 |
| PAY-02 Gọi thêm sau hóa đơn | A | Bản cũ VOID, bản mới tăng `phien_ban`, lưu `HOA_DON_PHIEN_BAN`. | POS; LED | 🔵 |
| PAY-03 QR cũ sau khi lập lại | D | Nội dung `HD{id}V{n}`; tiền cho bản VOID/cũ được lưu sổ, cờ `INVOICE_STALE`, admin áp vào bản mới. | LED `test_old_invoice_qr_after_reissue_…`, `test_money_arriving_while_the_invoice_is_voided_is_recorded` | `HD{id}` không phiên bản chỉ khớp bản 1. ✅ |
| PAY-04 Trả tiền lúc mở lại | A/E | Giữ khóa hóa đơn. | POS | ⚪ Đồng thời thật chưa kiểm. |
| PAY-05 Tiền mặt thiếu/đủ/dư | A | Thu một phần được; ghi ≤ số còn thiếu, `cashTendered` để tính tiền thối. **Sửa sau review (D):** khoản thu đang gửi được lưu trên máy POS (khóa + nội dung); mất phản hồi/5xx/hết giờ → "Gửi lại khoản thu" dùng đúng khóa cũ (tự gửi lại một lần sau khi tải lại trang); cùng khóa khác số tiền/hình thức → 409. | LED `test_underpaid_invoice_shows_the_shortfall_and_cash_can_top_it_up`; ORV `test_d_a_lost_response_retry_with_the_same_key_records_the_cash_once`; RR bước 2 | Khoản chờ gửi lại chỉ nằm trên trình duyệt đã thu (xóa dữ liệu trình duyệt thì mất nhắc). ✅ |
| PAY-06 Chuyển thiếu/dư | D | Dung sai 0; thiếu → `PARTIALLY_PAID` + thu bù; dư → áp đủ, phần dư "chưa phân bổ" chờ hoàn. | LED `test_overpaid_transfer_…`, `test_underpaid_invoice_…`; EX bước 7 | ✅ |
| PAY-07 Chuyển hai lần | D | Mỗi khoản tiền là một dòng `GIAO_DICH_TIEN`; lần hai cờ `DUPLICATE`, không tính doanh thu, chờ hoàn. **Sửa sau review (H):** quản lý ghi nhận thêm một khoản chuyển (mã khác) cho đơn đã đủ tiền trước đây bị 409 và không có dòng sổ; nay được ghi, cờ `DUPLICATE`/`NEEDS_REVIEW`, UI "Ghi nhận khoản chuyển thêm". | LED `test_a_second_transfer_…`; PRV `test_h_a_second_manual_receipt_for_a_paid_order_is_recorded_for_review_without_sales` | ✅ |
| PAY-08 Callback trùng/chậm | A | Idempotent theo định danh chuyển khoản `BANK:{ngân hàng}|{tài khoản nhận}|{mã}` (`TransferIdentity`). **Sửa sau review (A, B):** khóa cũ dùng nhãn nguồn (`VIETQR`/`MBBank`…) nên ghi tay và webhook của cùng một khoản thành hai dòng; nay tài khoản nhận đã cấu hình luôn quy về ngân hàng đã cấu hình. Webhook ký hợp lệ trỏ tới đơn/hóa đơn không tồn tại trước đây lỗi khóa ngoại (500, mất chứng từ); nay lưu `UNKNOWN_TARGET`/`NEEDS_REVIEW`. Migration tính lại khóa cũ; dòng trùng sau chuẩn hóa được gắn cờ, không gộp tiền. | LED `test_the_same_callback_delivered_twice_…`, `test_the_same_reference_at_different_banks_…`; PRV `test_a_…` (2 test), `test_b_a_signed_receipt_for_a_missing_order_or_invoice_is_stored_for_review`, `test_the_upgrade_rekeys_old_transfers_flags_collisions_for_review_and_keeps_old_refunds` | Bí danh ngân hàng là danh sách cấu hình (`restaurant.payments.bank_aliases`); nhãn lạ chưa có trong danh sách được giữ nguyên làm mã ngân hàng. ✅ |
| PAY-09 Tiền về sau khi hủy/đóng phiên | A/B·D | Lưu sổ với cờ `ORDER_CANCELLED`/`SESSION_CLOSED`, chờ hoàn/phân bổ; admin ghi nhận thủ công được. | LED `test_money_for_a_cancelled_order_…`, `test_paid_then_cancelled_order_…` | ✅ |
| PAY-10 Thủ công đồng thời callback | A/E | Hai đường gộp theo cùng định danh chuyển khoản (ngân hàng + tài khoản nhận + mã), không phụ thuộc nhãn nguồn (sửa sau review A). | LED `test_manual_confirmation_merges_with_a_callback_…`, `test_pos_transfer_reference_already_received_by_webhook_…`; PRV `test_a_a_manual_confirmation_and_the_bank_webhook_for_the_same_transfer_are_one_receipt` | ⚪ Đồng thời thật chưa kiểm (logic gộp đã có test; ràng buộc unique `khoa_duy_nhat` là chốt chặn cuối). |
| PAY-11 Một mã cho hai hóa đơn | D | `khoa_duy_nhat` unique; mã đã áp cho đơn khác → 409. | LED `test_one_bank_reference_cannot_pay_two_orders` | ✅ |
| PAY-12 Đổi phương thức | A·D | Như DLV-10. | DLV | ✅ |
| PAY-13 POS mã tùy ý | C | Mã ≥ 6 ký tự, unique, lưu người ghi nhận + thời điểm nhận/ghi. | LED `test_pos_transfer_requires_a_real_bank_reference` | ✅ |
| PAY-14 Hoàn một phần/lặp | A·C | `HOAN_TIEN`: toàn phần/một phần ≤ đã nhận − đã hoàn, ADMIN, lý do + chứng từ + phương thức + thời điểm. **Sửa sau review (C):** gửi lại cùng `Idempotency-Key` hoặc cùng chứng từ (cùng số tiền, hình thức) trả lại các dòng đã ghi — kể cả hoàn theo đơn trải nhiều giao dịch; cùng khóa/chứng từ khác nội dung → 409. **Sửa sau review (F):** hoàn vượt phần nhà hàng đang nợ trên đơn còn hiệu lực phải chọn `refundType=COMPENSATION` (422 nếu không) — giảm khoản phải thu (`so_tien_giam_tru`), hóa đơn giữ PAID, phiên POS đóng được; không còn trạng thái "PAID nhưng còn thiếu". | LED `test_partial_refunds_…`, `test_refund_of_the_surplus_…`; PRV `test_c_…` (2 test), `test_f_a_partial_compensation_after_payment_keeps_the_pos_session_closable` | Chính sách mới: hoàn toàn bộ đơn đã thanh toán mà không hủy đơn phải ghi là bồi hoàn (ADM `test_admin_can_confirm_a_reservation_and_refund_a_paid_payment`). ✅ |
| PAY-15 Hoàn tiền là ghi nhận | C | UI "Ghi nhận đã hoàn tiền", ghi rõ hệ thống không chuyển tiền. | EX bước 7; OPS | ✅ |
| PAY-16 Doanh thu sai | D | Báo cáo tách: giá trị bán, tiền nhận theo ngày về, hoàn theo ngày hoàn, chưa phân bổ, phải hoàn, còn nợ, hủy/miễn/làm lại/thất thu, bồi hoàn (`compensationValue`, trừ khỏi giá trị bán); có công thức. Món bán chạy theo phần tính tiền (sửa sau review M). | LED `test_report_counts_money_…`, `test_paid_then_cancelled_order_…`; ORV `test_m_…` | Tiền cũ không rõ ngày nhận tách riêng (`undatedLegacyReceived`). ✅ |
| PAY-17 Đối soát | B | Đối soát nội bộ sổ ↔ đơn + số khoản cần xem xét; ghi rõ chưa đối chiếu sao kê. | ADM; LED | Chưa nhập/đối chiếu sao kê ngân hàng thật. 🟡 |

## Thực đơn, quyền, lịch sử

| Case | Gốc | Thay đổi | Bằng chứng | Giới hạn còn lại |
|---|---|---|---|---|
| MNU-01 Ẩn danh mục | D | `Dish::publiclyListed()`/`isOrderable()` xét cả danh mục ở menu, giỏ, checkout, POS, QR; bỏ việc ghi đè cờ món khi xóa danh mục. | SES `test_a_hidden_category_hides_its_dishes_everywhere_without_touching_dish_flags` | ✅ |
| MNU-02 Đổi giá | A/B | Checkout/QR gửi `expectedTotal`; lệch → 409 `PRICE_CHANGED` kèm tổng mới; UI hỏi xác nhận. | DLV `test_checkout_asks_for_confirmation_when_a_price_changed`; SES | API vẫn chấp nhận request không kèm `expectedTotal` (giữ tương thích; frontend luôn gửi). 🟡 |
| MNU-03 Hết món nhiều người đặt | C | Không làm tồn kho (ngoài phạm vi); bếp báo hết món khi từ chối. | ITM `test_kitchen_rejection_…` | Không có số suất còn lại — không hiển thị giới hạn suất. 🟡 |
| MNU-04 Xóa mềm khi trong giỏ | A | Giữ nguyên. | `CustomerCheckoutFulfillmentApiTest` | 🔵 |
| AUTH-01 Khóa tài khoản | A | Giữ nguyên. | ADM | ✅ |
| AUTH-02 Gọi thẳng API | A·D | Các ngoại lệ RSV-07, DLV-10, RSV-15 chặn ở API; hủy món đã nấu gọi thẳng API → 403. | RSV, DLV, EX bước 5 | ✅ |
| AUTH-03 Ma trận quyền | C | STAFF: nhận khách, gia hạn/no-show, đổi bàn/số khách, hủy món chưa nấu, thu tiền. ADMIN: miễn tiền món đã nấu, hoàn tiền, thất thu, nhận khách ngoài khung; duyệt yêu cầu của STAFF trên tài khoản ADMIN. | ITM, RSV, LED | ✅ |
| AUD-01 Lịch sử | C | `NHAT_KY_THAO_TAC` (người, vai trò, thời điểm, hành động, đối tượng, lý do, trước/sau; lọc đệ quy token/mật khẩu/mã bàn); xem chỉ đọc ở phiên POS, đơn, lượt đặt; sổ giao dịch lưu người ghi nhận/hoàn. | AUD `test_audit_entries_never_store_tokens_passwords_or_join_codes`; SES; RSV | Không có màn nhật ký toàn hệ thống (chỉ theo đối tượng). ✅ |

## File tái hiện cũ

`docs/reviews/BusinessEdgeCaseAuditReproTest.php` được giữ làm bằng chứng lịch sử (không chạy trong suite chính).
Chạy lại sau milestone: **16/22 test fail** — đúng kỳ vọng vì lỗi đã sửa. 6 test còn pass khẳng định những đường cũ
vẫn bị chặn đúng (xóa/sửa món đã gửi, `KitchenService` không nhận REJECTED, `THANH_TOAN` là bản tổng hợp một dòng,
PENDING lưu trong DB cho đến khi lệnh quét chạy, checkout không kèm `expectedTotal`) — hành vi đúng nằm ở API mới
và được kiểm bởi test chính thức ở bảng trên. Chi tiết từng test ở đầu file tái hiện.

## Review A–N (2026-10-21)

Nguồn: `ExceptionMilestoneReviewTest.php` (11 test tái hiện — PASS nghĩa là lỗi còn), `FullAccessBoundaryReviewTest.php`,
`review-runtime-969d5a8b/browser-review.cjs`. Mỗi phát hiện được đọc code, tái hiện và đối chiếu chính sách trước khi sửa.
Test **tái hiện** của reviewer không được chỉnh cho xanh; bằng chứng sửa là test **nghiệm thu** trong suite chính (PRV/ORV)
và E2E trình duyệt (RR).

| # | Kết luận | Nguyên nhân gốc | Sửa | Nghiệm thu | Giới hạn |
|---|---|---|---|---|---|
| A | Lỗi thật | Khóa chuyển khoản dùng nhãn nguồn; ghi tay `VIETQR` và webhook `MBBank` cùng một khoản → hai khóa. | `TransferIdentity`: khóa theo ngân hàng + tài khoản nhận + mã; tài khoản đã cấu hình luôn quy về ngân hàng đã cấu hình; migration tính lại khóa cũ, trùng thì gắn cờ chứ không gộp tiền. | PRV `test_a_…` ×2, `test_the_upgrade_rekeys_…` | Bí danh ngân hàng theo danh sách cấu hình. Đồng thời thật chỉ kiểm trên SQLite. |
| B | Lỗi thật | Đích `DH`/`HD` lấy từ nội dung được ghi thẳng vào cột khóa ngoại → lỗi FK → 500, ngân hàng gửi lại mãi, chứng từ mất. | Chỉ giữ đích nếu đơn/hóa đơn tồn tại; không thì lưu `UNKNOWN_TARGET`/`NEEDS_REVIEW`, giữ nguyên nội dung để đối soát. | PRV `test_b_…` | — |
| C | Lỗi thật | Hoàn tiền không có khóa yêu cầu; bấm lại sau mất phản hồi ghi thêm một dòng hoàn. | `HOAN_TIEN.khoa_yeu_cau` theo `Idempotency-Key` hoặc chứng từ; gửi lại trả dòng cũ (kể cả hoàn đơn trải nhiều giao dịch); khác nội dung → 409. UI giữ một khóa cho mỗi lần mở modal. | PRV `test_c_…` ×2 | — |
| D | Lỗi thật | Frontend tạo khóa mới cho mỗi lần bấm/tải lại; backend chấp nhận cùng khóa với nội dung khác. | `usePendingPayment`: lưu khóa + nội dung, mất phản hồi giữ nguyên để gửi lại; backend 409 khi cùng khóa khác số tiền/hình thức/hóa đơn. | ORV `test_d_…`; RR bước 2 (mất phản hồi thật bằng chặn mạng + tải lại) | Nhắc gửi lại nằm ở bộ nhớ trình duyệt của máy đã thu. |
| E | Lỗi thật | Mọi món bị bếp từ chối: lập hóa đơn 0 ₫, hủy phiên (đã có món xuống bếp) và thất thu (không có nợ) đều bị chặn đúng luật nhưng không có lối ra. | `finish-without-charge` (lý do bắt buộc, điều kiện chặt, nhật ký). Test tái hiện của reviewer vẫn PASS vì chỉ khẳng định ba đường cũ bị chặn — đúng. | ORV `test_e_…` ×2 | — |
| F | Lỗi thật | Hoàn một phần sau thanh toán giảm số đã thu → thanh toán `PARTIALLY_PAID` trong khi hóa đơn `PAID`; POS không thu thêm, không mở lại, không đóng được. | Tách hoàn (nhà hàng nợ khách) và bồi hoàn (`COMPENSATION`, giảm khoản phải thu). Hoàn vượt phần đang nợ trên đơn còn hiệu lực phải chọn bồi hoàn. | PRV `test_f_…`; LED/ADM cập nhật | **Đổi hợp đồng:** request cũ không kèm `refundType` cho phần vượt → 422 (nêu rõ trong tài liệu API). |
| G | Lỗi thật | Sau khi đặt đơn, `fetchCart` làm giỏ rỗng → effect chuyển về giỏ trước khi modal VietQR mở. | Cờ `orderPlacedRef`, mở modal trước khi tải lại giỏ; đóng modal → Đơn hàng kèm hướng dẫn quét lại. | RR bước 1 | — |
| H | Lỗi thật | Ghi tay khoản thứ hai cho đơn đã đủ tiền → 409, tiền thật không có dòng sổ. | Ghi nhận, cờ `DUPLICATE`/`NEEDS_REVIEW`, không tính doanh thu; UI "Ghi nhận khoản chuyển thêm". | PRV `test_h_…` | — |
| I | Lỗi thật | Gia hạn chỉ kiểm lượt đặt kế tiếp, không kiểm phiên khách vãng lai đang ngồi. | 409 khi có phiên chồng khoảng giữ mới, gợi ý xếp bàn khác. | ORV `test_i_…` | — |
| J | Lỗi thật | Quyền đọc sau khi đóng phiên chỉ xét bàn quét ban đầu. | Xét lượt khách ACTIVE + phiên chứa bàn; phiên đóng còn trong thời hạn đọc. | ORV `test_j_…` | — |
| K | Lỗi thật | Cache gợi ý gồm cả kết quả lọc dị ứng theo vị trí, dùng chung giữa các thay đổi hồ sơ. | Chỉ cache thời tiết; gợi ý dựng lại mỗi lần với dị ứng hiện tại và thực đơn hiện tại (món/danh mục ẩn, hết bán). | ORV `test_k_…` | — |
| L | Lỗi thật | Màn bếp ẩn đơn hẹn nhưng API bếp vẫn nhận chuyển trạng thái. | API bếp 409 trước giờ xuống bếp; quản lý làm sớm phải có lý do, nhật ký `ORDER_STARTED_EARLY`. | ORV `test_l_…` | — |
| M | Lỗi thật | Món bán chạy cộng `so_luong × đơn giá` mọi dòng, gồm dòng làm lại 0 ₫/miễn/hủy. | Đếm phần tính tiền và `billedAmount`; tách `preparedQuantity`, `freeQuantity`. | ORV `test_m_…` | — |
| N | Lỗi thật | Luật `[class*="bg-amber-500"]` khớp cả nền mờ `/5`, `/10` → khối nền đỏ đặc với chữ tối (ví dụ "Nhận món tại Mộc Dining"). | Nền đặc chỉ cho lớp `bg-amber-500` đúng nghĩa hoặc `moc-inverse`; nền mờ thành sắc rượu vang nhạt; chữ amber trong `moc-inverse` sáng. | RR bước 3: đo tỉ lệ WCAG trên 35 màn ở 1440px và 390px — 0 vi phạm; chèn lại luật cũ thì bộ đo báo vi phạm (1,25 : 1) | Bộ đo kiểm chữ hiển thị trên nền đặc; không đo ảnh nền/gradient. |

**Chạy lại test tái hiện của reviewer sau khi sửa:** 10/11 FAIL (lỗi đã hết); 1 PASS là E như giải thích ở trên.
Hai test C và F nay dừng ở chính sách mới (hoàn vượt phần đang nợ phải chọn bồi hoàn) thay vì ghi hoàn lặp/kẹt POS.
`FullAccessBoundaryReviewTest`: 4/4 pass (ANONYMOUS 105, CUSTOMER 84, STAFF 61, ADMIN 19 endpoint bị chặn đúng),
gồm route mới `finish-without-charge`.

## Review lần 2 (2026-10-23)

Bằng chứng tái hiện: `ReviewFixesSecondPassTest.php` (2 test, PASS = lỗi còn). Sau khi sửa: **2/2 FAIL** (lỗi đã hết).
Nghiệm thu: **PRV2** `PaymentReviewSecondPassTest` (8 test) + E2E **RR** bước R.

| # | Kết luận | Nguyên nhân gốc | Sửa | Nghiệm thu | Giới hạn |
|---|---|---|---|---|---|
| 1 | Lỗi thật (A sửa chưa đúng) | `TransferIdentity` coi dãy số trùng tài khoản cấu hình là tài khoản cấu hình và ép ngân hàng về ngân hàng cấu hình, bỏ qua ngân hàng webhook nêu rõ → khoản Vietcombank bị trả `DUPLICATE_CALLBACK`, không lưu. Migration `2026_10_21_224546` dùng cùng quy tắc. | Ngân hàng nêu rõ luôn thắng; chỉ nguồn không nêu ngân hàng (nhập tay/POS/VietQR) mới quy về tài khoản cấu hình. Dãy số trùng nhưng khác ngân hàng → khoản riêng, chuyển đối soát kèm ghi chú. Cùng mã tham chiếu ở định danh khác mà một bên thiếu ngân hàng/tài khoản → ghi riêng, chuyển đối soát (không gộp đoán, không bỏ tiền). Migration mới `2026_10_23_090000` tính lại khóa; dòng bị gắn trùng nhầm được tách và chuyển đối soát; trùng thật giữ `#DUP`. | PRV2 `test_the_same_account_digits_at_two_named_banks_are_two_receipts`, `test_manual_pos_and_webhook_records_of_one_transfer_still_merge`, `test_an_incomplete_identity_matching_another_bank_is_kept_and_sent_to_review`, `test_the_correction_migration_separates_banks_merged_by_the_earlier_normalization` | Webhook đã bị trả `DUPLICATE_CALLBACK` trước bản sửa không để lại bản ghi (payload không lưu) — không khôi phục được từ DB; cần đối chiếu sao kê. |
| 2 | Lỗi thật | Một yêu cầu hoàn tạo **một** dòng `HOAN_TIEN` gắn một đơn (đơn có mã lớn nhất) trong khi dòng đảo phân bổ rút tiền từ nhiều đơn; "đã hoàn của đơn" cộng theo `HOAN_TIEN.ma_don_hang` → đơn kia hiện đã hoàn 0 ₫/UNPAID. | Một yêu cầu = nhiều dòng chi tiết cùng `khoa_yeu_cau`: mỗi đơn một dòng đúng số đã rút (gắn với dòng đảo của đơn đó), phần lấy từ tiền chưa phân bổ là dòng riêng (`so_tien_tu_phan_bo = 0`, gắn đơn đích của giao dịch). Gửi lại so khớp tổng các dòng. Migration mới tách dòng cũ theo dòng đảo phân bổ và tính lại `THANH_TOAN`. UI sổ giao dịch hiện đơn của từng dòng hoàn. | PRV2 `test_refunding_a_receipt_shared_by_two_cancelled_orders_refunds_each_order_its_own_part`, `test_partial_refunds_of_a_shared_receipt_track_each_order_until_fully_refunded`, `test_a_refund_taking_surplus_and_order_money_records_both_parts_on_the_paying_order`, `test_the_correction_migration_splits_an_old_refund_row_across_its_orders`; RR bước R | Phần hoàn từ tiền chưa phân bổ được tính vào "đã hoàn" của đơn đích giao dịch (người đã chuyển). |

Đã chứng minh test nghiệm thu bắt được lỗi: chạy PRV2 trên logic cũ (dựng lại tạm thời, đã khôi phục, so khớp sha256) → 5/8 FAIL;
3 test còn lại là test "vẫn phải gộp" và hai test migration (không phụ thuộc logic cũ).

**Migration:** `2026_10_23_090000` chỉ sửa dữ liệu (không đổi schema), **chưa áp dụng lên DB dev** (Pending). Đã chạy trên
bản sao bản sao lưu trước migration (chạy cả `224546` và `090000`) và trên DB E2E riêng: tổng tiền, tổng hoàn, tổng
`so_tien_da_hoan` không đổi; không có dòng ngân hàng mâu thuẫn hay dòng hoàn cần tách trong dữ liệu đó.
