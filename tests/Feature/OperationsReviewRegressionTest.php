<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\SessionEndType;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Admin\ReportService;
use App\Services\Customer\MenuService;
use App\Services\Customer\ProfileService;
use App\Services\Dining\GuestAccessService;
use App\Services\Staff\BillingService;
use App\Services\Staff\ItemCorrectionService;
use App\Services\Staff\KitchenService;
use App\Services\Staff\PosOrderService;
use App\Services\Staff\PosSessionService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nghiệm thu các phát hiện review vận hành: D (gửi lại thu tiền), E (phiên toàn món bị từ chối),
 * I (gia hạn khi bàn đã có khách), J (thẻ khách sau khi chuyển bàn và đóng phiên), K (gợi ý theo dị ứng
 * hiện tại), L (đơn hẹn qua API bếp), M (báo cáo món bán chạy).
 */
class OperationsReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
    }

    private function as(string $username): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->tokens[$username] ??= $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => '123'])->assertOk()->json('data.accessToken');
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$username]);
    }

    private function staff(): Account
    {
        return Account::where('ten_dang_nhap', 'staff1')->firstOrFail();
    }

    /** @return array{0: ServiceSession, 1: Order, 2: OrderItem} */
    private function sessionWithSentItem(string $tableName = 'Bàn T01', ?Dish $dish = null, int $quantity = 2): array
    {
        $table = DiningTable::where('ten_ban', $tableName)->firstOrFail();
        $session = app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'ops-review-'.uniqid()])['session'];
        $order = app(PosOrderService::class)->createDraft($session, $this->staff(), []);
        $dish ??= Dish::where('con_ban', true)->orderBy('ma_mon_an')->firstOrFail();
        $item = app(PosOrderService::class)->addItem($order, ['dishId' => $dish->ma_mon_an, 'quantity' => $quantity, 'clientRequestId' => 'ops-item-'.uniqid()]);
        app(PosOrderService::class)->sendKitchen($order, $this->staff(), [$item->ma_chi_tiet]);

        return [$session->fresh(), $order->fresh(), $item->fresh()];
    }

    private function cook(OrderItem $item): OrderItem
    {
        foreach ([KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY] as $step) {
            $item = app(KitchenService::class)->updateStatus($item->fresh(), $step, $this->staff());
        }

        return $item->fresh();
    }

    // D ---------------------------------------------------------------------------------------------

    public function test_d_a_lost_response_retry_with_the_same_key_records_the_cash_once(): void
    {
        [$session, , $item] = $this->sessionWithSentItem();
        $invoice = app(BillingService::class)->createInvoice($session);
        $pay = fn (array $body, string $key) => $this->as('staff1')->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/staff/invoices/{$invoice->ma_hoa_don}/payments", $body);

        $pay(['method' => 'CASH', 'amount' => 10000, 'cashTendered' => 10000], 'partial-cash-retry-01')->assertCreated();
        $pay(['method' => 'CASH', 'amount' => 10000, 'cashTendered' => 10000], 'partial-cash-retry-01')->assertCreated();
        $pay(['method' => 'CASH', 'amount' => 20000, 'cashTendered' => 20000], 'partial-cash-retry-01')->assertStatus(409);

        $this->assertSame(1, MoneyTransaction::where('idempotency_key', 'partial-cash-retry-01')->count());
        $this->assertEquals(10000, (float) Payment::where('ma_don_hang', $invoice->ma_don_hang)->value('so_tien_da_thu'));

        // Lần thu mới thật sự (khóa mới) vẫn được ghi.
        $pay(['method' => 'CASH', 'amount' => 10000, 'cashTendered' => 10000], 'partial-cash-second-02')->assertCreated();
        $this->assertEquals(20000, (float) Payment::where('ma_don_hang', $invoice->ma_don_hang)->value('so_tien_da_thu'));
        $this->assertNotNull($item);
    }

    // E ---------------------------------------------------------------------------------------------

    public function test_e_a_session_whose_items_were_all_rejected_can_be_finished_without_charge(): void
    {
        [$session, $order, $item] = $this->sessionWithSentItem();
        app(ItemCorrectionService::class)->reject($item, $this->staff(), ['reason' => 'OUT_OF_STOCK', 'note' => 'Hết toàn bộ nguyên liệu']);

        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$session->ma_phien}/finish-without-charge", [])->assertUnprocessable();
        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$session->ma_phien}/finish-without-charge", ['note' => 'Bếp hết nguyên liệu, khách về'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.endType', 'NO_CHARGE');

        $this->assertSame(SessionEndType::NO_CHARGE, $session->fresh()->kieu_ket_thuc);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->trang_thai);
        $this->assertSame(KitchenItemStatus::REJECTED, $item->fresh()->trang_thai_bep);
        $this->assertSame(TableStatus::CLEANING, DiningTable::where('ten_ban', 'Bàn T01')->first()->trang_thai);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SESSION_CLOSED_NO_CHARGE', 'ma_doi_tuong' => $session->ma_phien]);
    }

    public function test_e_finishing_without_charge_is_refused_while_anything_is_still_charged(): void
    {
        [$session, , $item] = $this->sessionWithSentItem(quantity: 1);
        $second = app(PosOrderService::class)->addItem($session->orders()->first(), ['dishId' => $item->ma_mon_an, 'quantity' => 1, 'clientRequestId' => 'ops-second']);
        app(PosOrderService::class)->sendKitchen($session->orders()->first(), $this->staff(), [$second->ma_chi_tiet]);
        app(ItemCorrectionService::class)->reject($item, $this->staff(), ['reason' => 'OUT_OF_STOCK', 'note' => 'Hết một phần']);

        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$session->ma_phien}/finish-without-charge", ['note' => 'Thử kết thúc'])->assertStatus(409);
        $this->assertSame(PosSessionStatus::OPEN, $session->fresh()->trang_thai);
        $this->assertSame(TableStatus::OCCUPIED, DiningTable::where('ten_ban', 'Bàn T01')->first()->trang_thai);
    }

    // I ---------------------------------------------------------------------------------------------

    public function test_i_a_hold_cannot_be_extended_after_the_table_was_given_to_walk_in_guests(): void
    {
        $this->travelTo(now()->setTime(19, 25));
        $table = DiningTable::where('ten_ban', 'Bàn T01')->firstOrFail();
        $reservation = Reservation::create([
            'thoi_gian' => now()->setTime(19, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $table->ma_ban,
        ]);
        app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'walk-in-after-grace-1']);

        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/extend", ['minutes' => 10, 'reason' => 'Khách gọi lại'])
            ->assertStatus(409);
        $this->assertNull($reservation->fresh()->gia_han_den);
    }

    // J ---------------------------------------------------------------------------------------------

    public function test_j_a_moved_guest_can_still_read_the_bill_at_the_new_table_after_closing_but_not_a_new_meal(): void
    {
        [$session, $order, $item] = $this->sessionWithSentItem();
        $oldTable = $session->tables->first();
        $entry = app(GuestAccessService::class)->join($oldTable, null, 'Khách chuyển bàn', $session->ma_tham_gia);
        $target = DiningTable::where('ten_ban', 'Bàn T02')->firstOrFail();
        app(PosSessionService::class)->changeTables($session, $this->staff(), [$target->ma_ban], 'Chuyển bàn cho khách');
        $this->cook($item);
        $invoice = app(BillingService::class)->createInvoice($session->fresh());
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $order->fresh()->tong_tien, 'idempotencyKey' => 'move-close-cash'], $this->staff()->ma_tai_khoan);
        app(BillingService::class)->close($session->fresh(), $session->fresh()->phien_ban);

        $this->flushHeaders();
        $this->withHeaders(['X-Dine-Visit' => $entry['token']])->getJson("/api/v1/public/dine/{$target->ma_qr}")
            ->assertOk()
            ->assertJsonPath('data.visit.status', 'ACTIVE')
            ->assertJsonPath('data.session.isCurrent', false)
            ->assertJsonPath('data.invoice.status', 'PAID');

        // Bàn mới có khách mới: thẻ cũ không vào được phiên mới, không gọi món được.
        app(PosSessionService::class)->completeCleaning($target->fresh());
        $newSession = app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$target->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'next-guests-new-table'])['session'];
        $visit = TableVisit::where('token_hash', TableVisit::hashToken($entry['token']))->firstOrFail();
        $this->assertFalse(app(GuestAccessService::class)->isBoundToSession($visit, $newSession));
        $this->flushHeaders();
        $this->withHeaders(['X-Dine-Visit' => $entry['token'], 'Idempotency-Key' => 'moved-guest-new-meal'])
            ->postJson("/api/v1/public/dine/{$target->ma_qr}/orders", ['items' => [['dishId' => $item->ma_mon_an, 'quantity' => 1]]])
            ->assertStatus(409);
        $this->withHeaders(['X-Dine-Visit' => $entry['token']])->getJson("/api/v1/public/dine/{$target->ma_qr}")
            ->assertOk()->assertJsonPath('data.entry.requiresJoinCode', true);
    }

    // K ---------------------------------------------------------------------------------------------

    public function test_k_recommendations_follow_the_current_allergies_and_the_current_menu(): void
    {
        $account = Account::where('ten_dang_nhap', 'kh1')->firstOrFail();
        app(ProfileService::class)->updateProfile($account, ['allergies' => []]);
        $first = app(MenuService::class)->getWeatherRecommendations(10.7, 106.6, $account->fresh());
        $withAllergen = collect($first['recommendedDishes'])->first(fn ($dish) => count($dish['allergens']) > 0);
        $this->assertNotNull($withAllergen);
        $allergen = $withAllergen['allergens'][0];

        app(ProfileService::class)->updateProfile($account->fresh(), ['allergies' => [$allergen]]);
        $after = app(MenuService::class)->getWeatherRecommendations(10.7, 106.6, $account->fresh());
        $this->assertSame([$allergen], $after['excludedAllergens']);
        $this->assertFalse(collect($after['recommendedDishes'])->contains('dishId', $withAllergen['dishId']));

        // Món/danh mục vừa ngừng bán hoặc ẩn biến mất ngay, không chờ hết cache.
        $shown = collect($after['recommendedDishes'])->first();
        Dish::whereKey($shown['dishId'])->update(['con_ban' => false]);
        $categoryId = Dish::whereKey(collect($after['recommendedDishes'])->last()['dishId'])->value('ma_danh_muc');
        Category::whereKey($categoryId)->update(['hien_thi' => false]);
        $latest = collect(app(MenuService::class)->getWeatherRecommendations(10.7, 106.6, $account->fresh())['recommendedDishes']);
        $this->assertFalse($latest->contains('dishId', $shown['dishId']));
        $this->assertFalse($latest->contains(fn ($dish) => Dish::whereKey($dish['dishId'])->value('ma_danh_muc') === $categoryId));
    }

    // L ---------------------------------------------------------------------------------------------

    public function test_l_the_kitchen_api_cannot_start_a_scheduled_order_early_but_a_manager_can_with_a_reason(): void
    {
        $order = Order::create([
            'ngay_dat_hang' => now(), 'tong_tien' => 50000, 'trang_thai' => OrderStatus::CONFIRMED,
            'thoi_gian_nhan_du_kien' => now()->addDay()->setTime(12, 0),
            'ten_nguoi_nhan' => 'Khách hẹn', 'so_dien_thoai_nhan' => '0901234567',
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'),
            'kenh' => OrderChannel::ONLINE, 'loai_phuc_vu' => FulfillmentType::PICKUP, 'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
        ]);
        Payment::create(['so_tien' => 50000, 'phuong_thuc' => PaymentMethod::CASH, 'trang_thai' => PaymentStatus::UNPAID, 'ma_don_hang' => $order->ma_don_hang]);
        $dish = Dish::where('con_ban', true)->firstOrFail();
        $item = OrderItem::create([
            'ma_don_hang' => $order->ma_don_hang, 'ma_mon_an' => $dish->ma_mon_an, 'ten_mon_snapshot' => $dish->ten_mon_an,
            'so_luong' => 1, 'so_luong_tinh_tien' => 1, 'don_gia' => 50000, 'trang_thai_bep' => KitchenItemStatus::SENT,
        ]);

        $this->as('staff1')->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => 'QUEUED'])->assertStatus(409);
        $this->assertSame(KitchenItemStatus::SENT, $item->fresh()->trang_thai_bep);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->trang_thai);

        $this->as('admin')->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", ['targetStatus' => 'PREPARING'])->assertUnprocessable();
        $this->as('admin')->patchJson("/api/v1/admin/orders/{$order->ma_don_hang}/status", ['targetStatus' => 'PREPARING', 'reason' => 'Khách gọi xin lấy sớm 11:00'])->assertOk();
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'ORDER_STARTED_EARLY', 'ma_doi_tuong' => $order->ma_don_hang, 'ly_do' => 'Khách gọi xin lấy sớm 11:00']);
        $this->assertTrue(app(KitchenService::class)->activeItems()->contains('ma_chi_tiet', $item->ma_chi_tiet));
        $this->as('staff1')->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => 'READY'])->assertOk();
    }

    // M ---------------------------------------------------------------------------------------------

    public function test_m_top_selling_counts_charged_portions_and_keeps_free_remakes_apart(): void
    {
        $dish = Dish::create([
            'ten_mon_an' => 'Món kiểm thử báo cáo', 'gia_tien' => 50000,
            'ma_danh_muc' => Category::where('hien_thi', true)->where('da_xoa', false)->value('ma_danh_muc'),
            'con_ban' => true, 'hien_thi' => true, 'da_xoa' => false,
        ]);
        [$session, $order, $item] = $this->sessionWithSentItem(dish: $dish, quantity: 3);
        app(ItemCorrectionService::class)->void($item->fresh(), $this->staff(), ['reason' => 'ORDER_ENTRY_ERROR', 'note' => 'Gọi dư một phần', 'quantity' => 1]);
        $item = $this->cook($item->fresh());
        $remade = app(ItemCorrectionService::class)->remake($item->fresh(), $this->staff(), ['reason' => 'RESTAURANT_MISTAKE', 'note' => 'Món mặn']);
        $this->cook($remade);
        $invoice = app(BillingService::class)->createInvoice($session->fresh());
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $invoice->tong_thanh_toan, 'idempotencyKey' => 'report-review-cash'], $this->staff()->ma_tai_khoan);
        app(BillingService::class)->close($session->fresh(), $session->fresh()->phien_ban);

        $row = collect(app(ReportService::class)->dashboard([])['topSellingDishes'])->firstWhere('dishId', $dish->ma_mon_an);

        $this->assertEquals(100000, (float) $order->fresh()->tong_tien);
        $this->assertSame(2, $row['totalSold']);
        $this->assertEquals(100000, $row['revenue']);
        // Bếp làm 2 phần gốc + 2 phần làm lại miễn phí; khách chỉ trả 2 phần.
        $this->assertSame((int) $item->fresh()->so_luong + (int) $remade->fresh()->so_luong, $row['preparedQuantity']);
        $this->assertSame((int) $remade->fresh()->so_luong, $row['freeQuantity']);
    }
}
