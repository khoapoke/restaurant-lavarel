<?php

/**
 * Kiểm toán ngoại lệ nghiệp vụ Mộc Dining — test TÁI HIỆN (ngoài repo, chạy SQLite in-memory).
 *
 * Mỗi test khẳng định HÀNH VI HIỆN TẠI để làm bằng chứng cho báo cáo
 * docs/reviews/business-edge-case-audit.md. Test pass = lỗi/khoảng trống vẫn còn.
 * Chạy: vendor/bin/phpunit docs/reviews/BusinessEdgeCaseAuditReproTest.php
 *
 * TRẠNG THÁI SAU MILESTONE NGOẠI LỆ (2026-10-21) — giữ file làm bằng chứng lịch sử, không sửa test.
 * Chạy lại: 16/22 test FAIL = lỗi đã sửa; test chính thức thay thế nằm trong tests/Feature
 * (bảng đầy đủ: docs/reviews/business-edge-case-mapping.md).
 *  ĐÃ SỬA  test_rsv_late_guest_keeps_the_table_locked_and_nothing_expires      → ReservationLifecycleApiTest
 *  ĐÃ SỬA  test_rsv_reservation_can_be_seated_any_time_the_same_day_through_the_api → test_seating_outside_the_window_needs_a_manager
 *  ĐÃ SỬA  test_rsv_a_reservation_cannot_be_seated_at_another_table_when_…    → test_reservation_for_another_table_needs_a_relocation_reason
 *  ĐÃ SỬA  test_rsv_customer_can_cancel_a_reservation_that_is_already_seated  → test_actual_guest_count_is_kept_apart_from_the_booking_…
 *  ĐÃ SỬA  test_rsv_customer_can_book_a_table_that_is_occupied_right_now      → test_a_table_with_guests_seated_now_is_not_offered_…
 *  ĐÃ SỬA  test_rsv_one_customer_can_hold_many_tables_and_book_outside_…      → test_an_account_cannot_hold_more_than_three_…, test_arrival_must_fall_inside_…
 *  ĐÃ SỬA  test_rsv_admin_can_complete_an_unseated_booking_and_admin_notes_…  → test_internal_notes_stay_internal_…
 *  ĐÃ SỬA  test_pos_no_routes_exist_to_change_guests_move_or_merge_tables     → SessionChangeAndMenuRulesApiTest
 *  ĐÃ SỬA  test_kds_a_pickup_scheduled_for_tomorrow_reaches_the_kitchen_…     → test_scheduled_times_must_fall_inside_ordering_hours
 *  ĐÃ SỬA  test_dlv_switching_a_cash_order_to_qr_mid_delivery_leaves_it_stuck → test_a_cash_order_cannot_switch_to_qr_once_it_is_on_the_road
 *  ĐÃ SỬA  test_pay_an_underpaid_table_invoice_transfer_leaves_no_record      → test_underpaid_invoice_shows_the_shortfall_…
 *  ĐÃ SỬA  test_pay_money_for_a_voided_invoice_is_rejected_without_a_trace    → test_money_arriving_while_the_invoice_is_voided_is_recorded
 *  ĐÃ SỬA  test_pay_the_same_bank_transaction_code_can_confirm_two_payments   → test_one_bank_reference_cannot_pay_two_orders
 *  ĐÃ SỬA  test_pay_paid_then_cancelled_order_counts_as_revenue_and_refund_…  → test_paid_then_cancelled_order_moves_from_sales_to_refund_due
 *  ĐÃ SỬA  test_pay_pos_transfer_is_recorded_with_any_code_and_no_cashier     → test_pos_transfer_requires_a_real_bank_reference
 *  ĐÃ SỬA  test_mnu_dishes_of_a_hidden_category_are_still_listed_and_sold     → test_a_hidden_category_hides_its_dishes_everywhere_…
 * Còn pass nhưng KHÔNG còn là lỗi (khẳng định đường cũ vẫn bị chặn đúng; luồng đúng nằm ở API mới):
 *  test_rsv_forgotten_pending_request_stays_pending_forever   — DB giữ PENDING tới khi lệnh quét chạy; API coi là EXPIRED ngay.
 *  test_kds_a_mistaken_item_sent_to_the_kitchen_cannot_be_…   — xóa/sửa dòng đã gửi vẫn bị chặn; hủy món qua /order-items/{id}/void.
 *  test_kds_an_item_that_can_never_be_cooked_blocks_closing_… — món QUEUED vẫn chặn đóng phiên; bếp dùng /kitchen/items/{id}/reject.
 *  test_pay_a_second_transfer_for_a_paid_order_is_silently_…  — THANH_TOAN là bản tổng hợp; lần hai nằm ở GIAO_DICH_TIEN (cờ DUPLICATE).
 *  test_pay_an_overpaid_order_is_marked_paid_for_the_order_… — đơn PAID theo giá trị đơn; phần dư chưa phân bổ trong sổ giao dịch.
 * Còn pass và VẪN là giới hạn:
 *  test_mnu_price_change_after_adding_to_cart_is_applied_…    — checkout không kèm expectedTotal vẫn lấy giá mới (frontend luôn gửi).
 */

use App\Enums\FulfillmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Admin\OrderService as AdminOrderService;
use App\Services\Admin\PaymentService as AdminPaymentService;
use App\Services\Admin\ReportService;
use App\Services\Admin\ReservationService as AdminReservationService;
use App\Services\Shared\TableStatusSynchronizer;
use App\Services\Staff\BillingService;
use App\Services\Staff\KitchenService;
use App\Services\Staff\PosOrderService;
use App\Services\Staff\PosSessionService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BusinessEdgeCaseAuditReproTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'audit-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(15, 0));
        $this->seed(RestaurantSeeder::class);
        config(['services.payment_webhook.secret' => self::SECRET]);
    }

    private function login(string $username): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => '123'])->assertOk()->json('data.accessToken');
    }

    private function staff(): Account
    {
        return Account::where('ten_dang_nhap', 'staff1')->firstOrFail();
    }

    private function freeTable(): DiningTable
    {
        return DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->orderBy('ma_ban')->firstOrFail();
    }

    private function dish(): Dish
    {
        return Dish::where('con_ban', true)->where('hien_thi', true)->where('da_xoa', false)->orderBy('ma_mon_an')->firstOrFail();
    }

    private function signedWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function openSessionWithSentItem(DiningTable $table, int $quantity = 1): array
    {
        $session = app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'audit-'.$table->ma_ban.'-'.uniqid()])['session'];
        $order = app(PosOrderService::class)->createDraft($session, $this->staff(), []);
        $item = app(PosOrderService::class)->addItem($order, ['dishId' => $this->dish()->ma_mon_an, 'quantity' => $quantity, 'clientRequestId' => 'audit-item-'.uniqid()]);
        app(PosOrderService::class)->sendKitchen($order, $this->staff(), [$item->ma_chi_tiet]);

        return [$session->fresh(), $order->fresh(), $item->fresh()];
    }

    private function onlineOrder(string $method = 'BANK_TRANSFER_QR', string $type = 'DELIVERY'): Order
    {
        $token = $this->login('kh1');
        $this->withToken($token)->postJson('/api/v1/customer/cart/items', ['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1])->assertSuccessful();
        $orderId = $this->withToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'fulfillmentType' => $type,
            'recipientName' => 'Khách kiểm toán',
            'recipientPhone' => '0901234567',
            'shippingAddress' => $type === 'DELIVERY' ? '12 Lê Lợi, Quận 1' : null,
            'paymentMethod' => $method,
        ])->assertSuccessful()->json('data.orderId');

        return Order::with('payment')->findOrFail($orderId);
    }

    // ───────────────────────── ĐẶT BÀN ─────────────────────────

    /** RSV-04/05/07: khách đặt 19:00, 19:21 chưa đến. */
    public function test_rsv_late_guest_keeps_the_table_locked_and_nothing_expires(): void
    {
        $table = $this->freeTable();
        $reservation = Reservation::create([
            'thoi_gian' => now()->setTime(19, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $table->ma_ban,
        ]);

        $this->travelTo(now()->setTime(19, 21));
        try {
            app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'walkin-1921-audit']);
            $this->fail('walk-in should be blocked');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('held for reservation', $exception->getMessage());
        }

        // Hold lasts the full 119-minute slot: walk-ins are still refused at 20:58.
        $this->travelTo(now()->setTime(20, 58));
        $this->assertNotNull(app(TableStatusSynchronizer::class)->conflictingReservation((int) $table->ma_ban));

        // Staff role cannot mark a no-show: reservation status changes are ADMIN-only.
        $this->withToken($this->login('staff1'))
            ->patchJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}/status", ['targetStatus' => 'CANCELLED'])
            ->assertForbidden();

        // At 23:59 the reservation is still CONFIRMED — there is no NO_SHOW status or expiry.
        $this->travelTo(now()->setTime(23, 59));
        $this->assertSame(ReservationStatus::CONFIRMED, $reservation->fresh()->trang_thai);
        $this->assertNull(ReservationStatus::tryFrom('NO_SHOW'));
    }

    /** RSV-08: khách đến 19:35 sau khi admin đã hủy lượt — API vẫn cho nhận bàn cả ngày khi lượt còn CONFIRMED. */
    public function test_rsv_reservation_can_be_seated_any_time_the_same_day_through_the_api(): void
    {
        $table = $this->freeTable();
        $reservation = Reservation::create([
            'thoi_gian' => now()->setTime(21, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $table->ma_ban,
        ]);

        // 15:00 — six hours early. UI hides the button, the API does not check a seating window.
        $session = app(PosSessionService::class)->open($this->staff(), [
            'tableIds' => [$table->ma_ban], 'guestCount' => 2, 'reservationId' => $reservation->ma_dat_ban, 'idempotencyKey' => 'very-early-seat-1',
        ])['session'];

        $this->assertSame(PosSessionStatus::OPEN, $session->trang_thai);
    }

    /** RSV-14: bàn đặt 19:00 vẫn có khách cũ ngồi — không thể nhận khách đặt sang bàn trống khác. */
    public function test_rsv_a_reservation_cannot_be_seated_at_another_table_when_its_table_is_still_busy(): void
    {
        $booked = $this->freeTable();
        $other = DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->where('ma_ban', '!=', $booked->ma_ban)->where('suc_chua', '>=', 2)->firstOrFail();
        app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$booked->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'long-lunch-1']);
        $reservation = Reservation::create([
            'thoi_gian' => now()->setTime(19, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $booked->ma_ban,
        ]);
        $this->travelTo(now()->setTime(19, 0));

        try {
            app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$other->ma_ban], 'guestCount' => 2, 'reservationId' => $reservation->ma_dat_ban, 'idempotencyKey' => 'seat-elsewhere-1']);
            $this->fail('seated elsewhere');
        } catch (HttpException $exception) {
            $this->assertSame('The reservation belongs to a different table.', $exception->getMessage());
        }
        $this->assertSame(TableStatus::OCCUPIED, $booked->fresh()->trang_thai);
    }

    /** RSV-15b: khách đã được nhận bàn (sớm) vẫn tự hủy được lượt đặt trên ứng dụng. */
    public function test_rsv_customer_can_cancel_a_reservation_that_is_already_seated(): void
    {
        $table = $this->freeTable();
        $reservation = Reservation::create([
            'thoi_gian' => now()->setTime(21, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $table->ma_ban,
        ]);
        app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'reservationId' => $reservation->ma_dat_ban, 'idempotencyKey' => 'seated-then-cancel']);

        $this->withToken($this->login('kh1'))->postJson("/api/v1/customer/reservations/{$reservation->ma_dat_ban}/cancel")->assertOk();
        $this->assertSame(ReservationStatus::CANCELLED, $reservation->fresh()->trang_thai);
        $this->assertSame(1, ServiceSession::where('ma_dat_ban', $reservation->ma_dat_ban)->where('trang_thai', PosSessionStatus::OPEN->value)->count());
    }

    /** RSV-01b: khách đặt bàn đang có khách vãng lai ngồi, giờ đến sau 30 phút. */
    public function test_rsv_customer_can_book_a_table_that_is_occupied_right_now(): void
    {
        $table = $this->freeTable();
        app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'walkin-before-booking']);
        $this->assertSame(TableStatus::OCCUPIED, $table->fresh()->trang_thai);

        $token = $this->login('kh1');
        $time = now()->addMinutes(35);
        $this->withToken($token)->getJson('/api/v1/customer/tables/available?'.http_build_query(['date' => $time->format('Y-m-d'), 'time' => $time->format('H:i'), 'guestCount' => 2]))
            ->assertOk()
            ->assertJsonFragment(['tableId' => $table->ma_ban]);
        $this->withToken($token)->postJson('/api/v1/customer/reservations', [
            'tableId' => $table->ma_ban, 'reservationTime' => $time->format('Y-m-d H:i:s'), 'guestCount' => 2,
        ])->assertCreated();
    }

    /** RSV-02 + RSV-16: một khách giữ nhiều bàn cùng giờ; đặt lúc 03:00 sáng. */
    public function test_rsv_one_customer_can_hold_many_tables_and_book_outside_opening_hours(): void
    {
        $token = $this->login('kh1');
        $slot = now()->addDay()->setTime(19, 0)->format('Y-m-d H:i:s');
        $tables = DiningTable::where('trang_thai', '!=', TableStatus::MAINTENANCE->value)->where('suc_chua', '>=', 2)->limit(3)->pluck('ma_ban');
        foreach ($tables as $tableId) {
            $this->withToken($token)->postJson('/api/v1/customer/reservations', ['tableId' => $tableId, 'reservationTime' => $slot, 'guestCount' => 2])->assertCreated();
        }
        $this->assertSame(3, Reservation::where('thoi_gian', $slot)->where('trang_thai', ReservationStatus::PENDING->value)->count());

        $this->withToken($token)->postJson('/api/v1/customer/reservations', [
            'tableId' => $tables->first(), 'reservationTime' => now()->addDays(2)->setTime(3, 0)->format('Y-m-d H:i:s'), 'guestCount' => 2,
        ])->assertCreated();
    }

    /** RSV-03: PENDING bị bỏ quên qua giờ — không ai duyệt được nữa, khách không hủy được, mãi PENDING. */
    public function test_rsv_forgotten_pending_request_stays_pending_forever(): void
    {
        $reservation = Reservation::create([
            'thoi_gian' => now()->setTime(18, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::PENDING,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $this->freeTable()->ma_ban,
        ]);
        $this->travelTo(now()->setTime(20, 30));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('This reservation time has already passed');
        try {
            app(AdminReservationService::class)->updateStatus($reservation->ma_dat_ban, 'CONFIRMED');
        } finally {
            $this->assertSame(ReservationStatus::PENDING, $reservation->fresh()->trang_thai);
        }
    }

    /** RSV-15: admin “hoàn thành” một lượt chưa từng nhận bàn; ghi chú admin hiện cho khách. */
    public function test_rsv_admin_can_complete_an_unseated_booking_and_admin_notes_are_customer_visible(): void
    {
        $reservation = Reservation::where('trang_thai', ReservationStatus::CONFIRMED->value)->firstOrFail();
        app(AdminReservationService::class)->updateStatus($reservation->ma_dat_ban, 'COMPLETED', 'Khách không đến, VIP cũ');

        $this->assertSame(ReservationStatus::COMPLETED, $reservation->fresh()->trang_thai);
        $this->assertSame(0, ServiceSession::where('ma_dat_ban', $reservation->ma_dat_ban)->count());

        $notes = collect($this->withToken($this->login('kh1'))->getJson('/api/v1/customer/reservations')->json('data.items'))
            ->firstWhere('reservationId', $reservation->ma_dat_ban)['note'];
        $this->assertStringContainsString('[ADMIN] Khách không đến, VIP cũ', $notes);
    }

    // ─────────────────────── PHIÊN & BẾP ───────────────────────

    /** POS-07/KDS-01: gọi nhầm món đã gửi bếp — không có cách bỏ món, món bị tính tiền. */
    public function test_kds_a_mistaken_item_sent_to_the_kitchen_cannot_be_voided_and_is_billed(): void
    {
        [$session, $order, $item] = $this->openSessionWithSentItem($this->freeTable());

        foreach ([fn () => app(PosOrderService::class)->deleteItem($item), fn () => app(PosOrderService::class)->updateItem($item, ['quantity' => 0])] as $attempt) {
            try {
                $attempt();
                $this->fail('sent item changed');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        try {
            app(PosSessionService::class)->cancel($session, 'Gọi nhầm món');
            $this->fail('session cancelled');
        } catch (HttpException $exception) {
            $this->assertStringContainsString('Settle the bill', $exception->getMessage());
        }
        try {
            app(KitchenService::class)->updateStatus($item, KitchenItemStatus::REJECTED);
            $this->fail('item rejected');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $invoice = app(BillingService::class)->createInvoice($session);
        $this->assertEquals((float) $item->don_gia, (float) $invoice->tong_thanh_toan);
    }

    /** KDS-03: bếp hết nguyên liệu sau khi nhận món → phiên không thể đóng, không có lối ra. */
    public function test_kds_an_item_that_can_never_be_cooked_blocks_closing_the_session(): void
    {
        [$session, , $item] = $this->openSessionWithSentItem($this->freeTable());
        app(KitchenService::class)->updateStatus($item, KitchenItemStatus::QUEUED);
        $invoice = app(BillingService::class)->createInvoice($session);
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $invoice->tong_thanh_toan, 'idempotencyKey' => 'cash-stuck-item-01']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('All kitchen items must be READY or SERVED before closing.');
        app(BillingService::class)->close($session, ServiceSession::findOrFail($session->ma_phien)->phien_ban);
    }

    /** POS-04/05: không có API đổi số khách, đổi bàn, ghép/tách bàn sau khi mở phiên. */
    public function test_pos_no_routes_exist_to_change_guests_move_or_merge_tables(): void
    {
        $paths = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->implode("\n");
        foreach (['guest-count', 'move', 'transfer', 'merge', 'split'] as $word) {
            $this->assertStringNotContainsString($word, $paths);
        }
    }

    /** KDS-09: đơn tự lấy hẹn ngày mai xuất hiện ngay trên KDS khi được xác nhận. */
    public function test_kds_a_pickup_scheduled_for_tomorrow_reaches_the_kitchen_immediately(): void
    {
        $token = $this->login('kh1');
        $this->withToken($token)->postJson('/api/v1/customer/cart/items', ['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1])->assertSuccessful();
        $orderId = $this->withToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'fulfillmentType' => 'PICKUP', 'recipientName' => 'Hẹn mai', 'recipientPhone' => '0901234567',
            'paymentMethod' => 'CASH', 'scheduledAt' => now()->addDay()->setTime(12, 0)->format('Y-m-d H:i:s'),
        ])->json('data.orderId');
        app(AdminOrderService::class)->updateStatus($orderId, 'CONFIRMED');

        $this->assertTrue(app(KitchenService::class)->activeItems()->contains('ma_don_hang', $orderId));
    }

    // ─────────────────────── GIAO HÀNG ───────────────────────

    /** DLV-04 + PAY-12: khách đổi đơn tiền mặt sang QR khi tài xế đang giao → không hoàn tất, không hủy được. */
    public function test_dlv_switching_a_cash_order_to_qr_mid_delivery_leaves_it_stuck(): void
    {
        $order = $this->onlineOrder('CASH');
        $order->update(['trang_thai' => OrderStatus::READY, 'trang_thai_giao_nhan' => FulfillmentStatus::OUT_FOR_DELIVERY]);

        $this->withToken($this->login('kh1'))->postJson("/api/v1/customer/payments/{$order->ma_don_hang}/qr")->assertOk();
        $this->assertSame(PaymentMethod::BANK_TRANSFER_QR, $order->payment->fresh()->phuong_thuc);

        foreach ([fn () => app(AdminOrderService::class)->updateFulfillmentStatus($order->ma_don_hang, 'DELIVERED'), fn () => app(AdminOrderService::class)->cancel($order->ma_don_hang, 'Khách không nhận')] as $attempt) {
            try {
                $attempt();
                $this->fail('order moved');
            } catch (HttpException $exception) {
                $this->assertContains($exception->getStatusCode(), [400, 409]);
            }
        }
        $this->assertSame(FulfillmentStatus::OUT_FOR_DELIVERY, $order->fresh()->trang_thai_giao_nhan);
    }

    // ─────────────────── THANH TOÁN & HOÀN TIỀN ───────────────────

    /** PAY-06: khách chuyển khoản hai lần cho cùng đơn — lần hai được trả 200, không để lại dấu vết. */
    public function test_pay_a_second_transfer_for_a_paid_order_is_silently_absorbed(): void
    {
        $order = $this->onlineOrder();
        $amount = (float) $order->tong_tien;
        $this->signedWebhook(['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => $amount, 'transactionCode' => 'FT-FIRST'])->assertOk();
        $logAfterFirst = $order->payment->fresh()->log_callback;

        $this->signedWebhook(['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => $amount, 'transactionCode' => 'FT-SECOND'])->assertOk();

        $payment = $order->payment->fresh();
        $this->assertSame('FT-FIRST', $payment->ma_giao_dich);
        $this->assertSame($logAfterFirst, $payment->log_callback);
        $this->assertSame(1, Payment::where('ma_don_hang', $order->ma_don_hang)->count());
    }

    /** PAY-05: chuyển dư cho đơn — chấp nhận, phần dư không được ghi nhận riêng. */
    public function test_pay_an_overpaid_order_is_marked_paid_for_the_order_amount_only(): void
    {
        $order = $this->onlineOrder();
        $this->signedWebhook(['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => (float) $order->tong_tien + 500000, 'transactionCode' => 'FT-OVER'])->assertOk();

        $payment = $order->payment->fresh();
        $this->assertSame(PaymentStatus::PAID, $payment->trang_thai);
        $this->assertEquals((float) $order->tong_tien, (float) $payment->so_tien);
    }

    /** PAY-05b: chuyển thiếu cho hóa đơn tại bàn — 400, không lưu bất kỳ dấu vết nào (khác luồng đơn online). */
    public function test_pay_an_underpaid_table_invoice_transfer_leaves_no_record(): void
    {
        [$session] = $this->openSessionWithSentItem($this->freeTable(), 2);
        $invoice = app(BillingService::class)->createInvoice($session);

        $this->signedWebhook(['content' => "HD{$invoice->ma_hoa_don} THANH TOAN", 'transferAmount' => 1000, 'transactionCode' => 'FT-SHORT'])->assertStatus(400);

        $this->assertSame(InvoiceStatus::OPEN, $invoice->fresh()->trang_thai);
        $this->assertSame(0, Payment::where('ma_hoa_don', $invoice->ma_hoa_don)->count());
        $this->assertSame(0, Payment::where('ma_giao_dich', 'FT-SHORT')->count());
    }

    /** PAY-03/09: khách quét QR cũ sau khi hóa đơn bị mở lại (VOID) — tiền về bị từ chối, không lưu. */
    public function test_pay_money_for_a_voided_invoice_is_rejected_without_a_trace(): void
    {
        [$session] = $this->openSessionWithSentItem($this->freeTable());
        $invoice = app(BillingService::class)->createInvoice($session);
        app(BillingService::class)->reopen($session);

        $this->signedWebhook(['content' => "HD{$invoice->ma_hoa_don} THANH TOAN", 'transferAmount' => (float) $invoice->tong_thanh_toan, 'transactionCode' => 'FT-VOID'])->assertStatus(409);
        $this->assertSame(InvoiceStatus::VOID, $invoice->fresh()->trang_thai);
        $this->assertSame(0, Payment::where('ma_giao_dich', 'FT-VOID')->count());
    }

    /** PAY-11: một mã giao dịch ngân hàng dùng để xác nhận hai khoản khác nhau. */
    public function test_pay_the_same_bank_transaction_code_can_confirm_two_payments(): void
    {
        $first = $this->onlineOrder();
        $second = $this->onlineOrder();
        $admin = Account::where('ten_dang_nhap', 'admin')->firstOrFail();

        app(AdminPaymentService::class)->confirmTransfer($first->payment->ma_thanh_toan, $admin, 'FT26102199999');
        app(AdminPaymentService::class)->confirmTransfer($second->payment->ma_thanh_toan, $admin, 'FT26102199999');

        $this->assertSame(2, Payment::where('ma_giao_dich', 'FT26102199999')->where('trang_thai', PaymentStatus::PAID->value)->count());
    }

    /** PAY-14/15: hủy đơn đã trả — doanh thu vẫn tính đến khi hoàn; hoàn tiền chỉ đổi trạng thái, toàn phần. */
    public function test_pay_paid_then_cancelled_order_counts_as_revenue_and_refund_is_a_status_flip(): void
    {
        $order = $this->onlineOrder();
        $this->signedWebhook(['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => (float) $order->tong_tien, 'transactionCode' => 'FT-CANCEL'])->assertOk();
        $before = app(ReportService::class)->dashboard([])['revenue']['totalPaidRevenue'];
        app(AdminOrderService::class)->cancel($order->ma_don_hang, 'Hết nguyên liệu');

        $after = app(ReportService::class)->dashboard([]);
        $this->assertEquals($before, $after['revenue']['totalPaidRevenue']);
        $this->assertSame(1, $after['attention']['refundsPending']);

        $refunded = app(AdminPaymentService::class)->refund($order->payment->ma_thanh_toan, 'Hoàn do hủy');
        $this->assertSame(PaymentStatus::REFUNDED, $refunded->trang_thai);
        $this->assertEquals((float) $order->tong_tien, (float) $refunded->so_tien);
        $this->assertArrayNotHasKey('hoan_tien_luc', $refunded->getAttributes());
    }

    /** PAY-13 + AUD-01: POS ghi nhận chuyển khoản với mã tùy ý; không lưu nhân viên thu tiền. */
    public function test_pay_pos_transfer_is_recorded_with_any_code_and_no_cashier(): void
    {
        [$session] = $this->openSessionWithSentItem($this->freeTable());
        $invoice = app(BillingService::class)->createInvoice($session);
        $payment = app(BillingService::class)->pay($invoice, ['method' => 'BANK_TRANSFER_QR', 'amount' => (float) $invoice->tong_thanh_toan, 'transactionCode' => 'x', 'idempotencyKey' => 'any-code-001']);

        $this->assertSame('x', $payment->ma_giao_dich);
        $this->assertArrayNotHasKey('ma_nhan_vien', $payment->getAttributes());
    }

    // ─────────────────────── THỰC ĐƠN ───────────────────────

    /** MNU-01: ẩn danh mục (không xóa) — món trong danh mục vẫn hiện và vẫn checkout được. */
    public function test_mnu_dishes_of_a_hidden_category_are_still_listed_and_sold(): void
    {
        $dish = $this->dish();
        Category::where('ma_danh_muc', $dish->ma_danh_muc)->update(['hien_thi' => false]);

        $this->getJson('/api/v1/customer/categories')->assertJsonMissing(['categoryId' => $dish->ma_danh_muc]);
        $this->getJson('/api/v1/customer/dishes?perPage=100')->assertJsonFragment(['dishId' => $dish->ma_mon_an]);

        $order = $this->onlineOrder('CASH', 'PICKUP');
        $this->assertTrue($order->dishes->contains('ma_mon_an', $dish->ma_mon_an));
    }

    /** MNU-02: giá đổi giữa lúc bỏ giỏ và checkout — đơn lấy giá mới (đúng), khách không được báo. */
    public function test_mnu_price_change_after_adding_to_cart_is_applied_silently_at_checkout(): void
    {
        $dish = $this->dish();
        $token = $this->login('kh1');
        $this->withToken($token)->postJson('/api/v1/customer/cart/items', ['dishId' => $dish->ma_mon_an, 'quantity' => 1])->assertSuccessful();
        $dish->update(['gia_tien' => (float) $dish->gia_tien + 20000]);

        $orderId = $this->withToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'fulfillmentType' => 'PICKUP', 'recipientName' => 'Đổi giá', 'recipientPhone' => '0901234567', 'paymentMethod' => 'CASH',
        ])->assertSuccessful()->json('data.orderId');

        $this->assertEquals((float) $dish->gia_tien, (float) OrderItem::where('ma_don_hang', $orderId)->where('ma_mon_an', $dish->ma_mon_an)->value('don_gia'));
    }
}
