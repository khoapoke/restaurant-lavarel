<?php

namespace Tests\Feature;

use App\Enums\KitchenItemStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Đổi số khách, chuyển/ghép bàn giữ nguyên phiên (SES-04/05/06/08), thẻ QR đi theo phiên,
 * danh mục ẩn áp dụng nhất quán (MNU-01), giờ nhận gọi món và xác nhận lại khi giá đổi (MNU-02).
 */
class SessionChangeAndMenuRulesApiTest extends TestCase
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

    private function newTable(string $name, int $capacity = 4): DiningTable
    {
        return DiningTable::create(['ten_ban' => $name, 'suc_chua' => $capacity, 'trang_thai' => TableStatus::AVAILABLE, 'vi_tri' => 'Khu thử nghiệm']);
    }

    private function dish(): Dish
    {
        return Dish::where('con_ban', true)->where('hien_thi', true)->where('da_xoa', false)->orderBy('ma_mon_an')->firstOrFail();
    }

    /** Khách quét QR, nhân viên nhận; trả về [visitToken, sessionId]. */
    private function seatedGuest(DiningTable $table): array
    {
        $this->flushHeaders();
        $visitToken = $this->postJson("/api/v1/public/dine/{$table->ma_qr}/check-in", ['guestName' => 'Anh Nam', 'guestCount' => 2])->assertCreated()->json('data.visitToken');
        $visit = TableVisit::where('token_hash', TableVisit::hashToken($visitToken))->firstOrFail();
        $sessionId = $this->as('staff1')->postJson("/api/v1/staff/live/check-ins/{$visit->ma_luot}/accept", ['guestCount' => 2])->assertOk()->json('data.sessionId');

        return [$visitToken, $sessionId];
    }

    private function guestOrder(DiningTable $table, string $visitToken, array $body, string $key)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['X-Dine-Visit' => $visitToken, 'Idempotency-Key' => $key])->postJson("/api/v1/public/dine/{$table->ma_qr}/orders", $body);
    }

    public function test_guest_count_changes_within_capacity_and_is_audited(): void
    {
        $table = $this->newTable('Bàn Thử Số Khách', 4);
        [, $sessionId] = $this->seatedGuest($table);

        $this->as('staff1')->patchJson("/api/v1/staff/pos/sessions/{$sessionId}/guest-count", ['guestCount' => 5])->assertUnprocessable();
        $this->as('staff1')->patchJson("/api/v1/staff/pos/sessions/{$sessionId}/guest-count", ['guestCount' => 4, 'reason' => 'Thêm 2 bạn đến muộn'])
            ->assertOk()->assertJsonPath('data.guestCount', 4);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SESSION_GUEST_COUNT_CHANGED', 'ma_doi_tuong' => $sessionId]);
    }

    public function test_moving_a_session_keeps_its_items_and_the_guest_pass_follows_it(): void
    {
        $from = $this->newTable('Bàn Thử Cũ');
        $to = $this->newTable('Bàn Thử Mới');
        [$visitToken, $sessionId] = $this->seatedGuest($from);
        $this->guestOrder($from, $visitToken, ['items' => [['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1]]], 'move-round-0000001')->assertCreated();

        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/tables", ['tableIds' => [$to->ma_ban]])->assertUnprocessable();
        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/tables", ['tableIds' => [$to->ma_ban], 'reason' => 'Khách muốn ngồi gần cửa sổ'])
            ->assertOk()->assertJsonPath('data.sessionId', $sessionId);

        $this->assertSame(TableStatus::CLEANING, $from->fresh()->trang_thai);
        $this->assertSame(TableStatus::OCCUPIED, $to->fresh()->trang_thai);
        $this->assertSame(1, OrderItem::whereHas('order', fn ($q) => $q->where('ma_phien', $sessionId))->count());

        // QR cũ báo đã chuyển bàn và không cho gọi món; QR bàn mới nhận luôn thẻ khách.
        $this->flushHeaders();
        $this->withHeaders(['X-Dine-Visit' => $visitToken])->getJson("/api/v1/public/dine/{$from->ma_qr}")
            ->assertOk()->assertJsonPath('data.entry.movedTo.tableNames', ['Bàn Thử Mới'])->assertJsonPath('data.entry.movedTo.tableToken', $to->ma_qr);
    }

    public function test_the_guest_pass_works_on_the_new_table_after_a_move(): void
    {
        $from = $this->newTable('Bàn Thử Cũ 2');
        $to = $this->newTable('Bàn Thử Mới 2');
        [$visitToken, $sessionId] = $this->seatedGuest($from);
        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/tables", ['tableIds' => [$to->ma_ban], 'reason' => 'Bàn cũ hỏng chân', 'releaseAs' => 'MAINTENANCE'])->assertOk();

        $this->assertSame(TableStatus::MAINTENANCE, $from->fresh()->trang_thai);
        $this->guestOrder($from, $visitToken, ['items' => [['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1]]], 'old-table-round-001')->assertStatus(409);
        $this->guestOrder($to, $visitToken, ['items' => [['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1]]], 'new-table-round-001')->assertCreated();
    }

    public function test_tables_can_be_merged_but_not_onto_a_table_held_for_a_reservation(): void
    {
        $main = $this->newTable('Bàn Thử Chính', 2);
        $held = $this->newTable('Bàn Thử Giữ', 4);
        $free = $this->newTable('Bàn Thử Ghép', 4);
        Reservation::create([
            'thoi_gian' => now()->addMinutes(30), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::CONFIRMED,
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'), 'ma_ban' => $held->ma_ban,
        ]);
        [, $sessionId] = $this->seatedGuest($main);

        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/tables", ['tableIds' => [$main->ma_ban, $held->ma_ban], 'reason' => 'Thêm khách'])->assertStatus(409);
        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/tables", ['tableIds' => [$main->ma_ban, $free->ma_ban], 'reason' => 'Thêm khách'])->assertOk();
        $this->as('staff1')->patchJson("/api/v1/staff/pos/sessions/{$sessionId}/guest-count", ['guestCount' => 6])->assertOk();

        $this->assertSame(TableStatus::OCCUPIED, $free->fresh()->trang_thai);
        $this->assertSame(2, ServiceSession::find($sessionId)->tables()->count());
    }

    public function test_a_hidden_category_hides_its_dishes_everywhere_without_touching_dish_flags(): void
    {
        $dish = $this->dish();
        $category = Category::findOrFail($dish->ma_danh_muc);
        $category->update(['hien_thi' => false]);

        $this->flushHeaders();
        $this->assertNotContains($dish->ma_mon_an, collect($this->getJson('/api/v1/menu/dishes?perPage=100')->json('data.items'))->pluck('dishId')->all());
        $this->getJson("/api/v1/menu/dishes/{$dish->ma_mon_an}")->assertNotFound();
        $this->as('kh1')->postJson('/api/v1/customer/cart/items', ['dishId' => $dish->ma_mon_an, 'quantity' => 1])->assertStatus(400);

        $table = $this->newTable('Bàn Thử Menu');
        [$visitToken, $sessionId] = $this->seatedGuest($table);
        $this->guestOrder($table, $visitToken, ['items' => [['dishId' => $dish->ma_mon_an, 'quantity' => 1]]], 'hidden-cat-round-01')->assertNotFound();
        $orderId = $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/orders")->json('data.orderId');
        $this->as('staff1')->postJson("/api/v1/staff/pos/orders/{$orderId}/items", ['dishId' => $dish->ma_mon_an, 'quantity' => 1, 'clientRequestId' => 'hidden-cat-pos-01'])->assertStatus(409);

        // Món bị tắt riêng vẫn tắt khi danh mục hiện lại; xóa danh mục không ghi đè cờ món.
        $dish->update(['con_ban' => false]);
        $category->update(['hien_thi' => true]);
        $this->assertFalse($dish->fresh()->isOrderable());
        $this->assertTrue((bool) $dish->fresh()->hien_thi);
        $this->as('admin')->deleteJson("/api/v1/admin/categories/{$category->ma_danh_muc}")->assertOk();
        $this->assertTrue((bool) $dish->fresh()->hien_thi);
        $this->assertFalse($dish->fresh()->isListed());
    }

    public function test_new_orders_stop_at_last_order_time_but_open_bills_can_still_be_settled(): void
    {
        $table = $this->newTable('Bàn Thử Giờ');
        [$visitToken, $sessionId] = $this->seatedGuest($table);
        $orderId = $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/orders")->json('data.orderId');
        $itemId = $this->as('staff1')->postJson("/api/v1/staff/pos/orders/{$orderId}/items", ['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1, 'clientRequestId' => 'late-night-item-01'])->json('data.itemId');
        $this->as('staff1')->postJson("/api/v1/staff/pos/orders/{$orderId}/send-kitchen", ['itemIds' => [$itemId]])->assertOk();
        OrderItem::whereKey($itemId)->update(['trang_thai_bep' => KitchenItemStatus::SERVED->value]);

        $this->travelTo(now()->setTime(22, 5));
        $this->tokens = [];
        $secondId = $this->as('staff1')->postJson("/api/v1/staff/pos/orders/{$orderId}/items", ['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1, 'clientRequestId' => 'late-night-item-02'])->json('data.itemId');
        $this->as('staff1')->postJson("/api/v1/staff/pos/orders/{$orderId}/send-kitchen", ['itemIds' => [$secondId]])->assertStatus(409);
        $this->guestOrder($table, $visitToken, ['items' => [['dishId' => $this->dish()->ma_mon_an, 'quantity' => 1]]], 'late-night-round-01')->assertStatus(409);
        $this->as('staff1')->withHeader('Idempotency-Key', 'late-walk-in-0001')->postJson('/api/v1/staff/pos/sessions', ['tableIds' => [$this->newTable('Bàn Thử Đêm')->ma_ban], 'guestCount' => 2])->assertStatus(409);

        $this->as('staff1')->deleteJson("/api/v1/staff/pos/order-items/{$secondId}");
        $invoice = $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")->assertCreated();
        $this->as('staff1')->withHeader('Idempotency-Key', 'late-night-pay-0001')->postJson("/api/v1/staff/invoices/{$invoice->json('data.invoiceId')}/payments", [
            'method' => 'CASH', 'amount' => $invoice->json('data.totalAmount'),
        ])->assertCreated();
        $version = $this->as('staff1')->getJson("/api/v1/staff/pos/sessions/{$sessionId}")->json('data.version');
        $this->as('staff1')->postJson("/api/v1/staff/pos/sessions/{$sessionId}/close", ['expectedVersion' => $version])->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_a_qr_round_with_stale_prices_asks_for_confirmation_and_retries_stay_idempotent(): void
    {
        $table = $this->newTable('Bàn Thử Giá');
        $dish = $this->dish();
        [$visitToken] = $this->seatedGuest($table);
        $line = ['items' => [['dishId' => $dish->ma_mon_an, 'quantity' => 2]]];
        $shownTotal = (float) $dish->gia_tien * 2;

        $this->guestOrder($table, $visitToken, [...$line, 'expectedTotal' => $shownTotal], 'price-ok-round-0001')->assertCreated();
        $dish->update(['gia_tien' => (float) $dish->gia_tien + 3000]);

        // Gửi lại lượt đã nhận (mất phản hồi) vẫn được xác nhận dù giá vừa đổi.
        $this->guestOrder($table, $visitToken, [...$line, 'expectedTotal' => $shownTotal], 'price-ok-round-0001')->assertOk()->assertJsonPath('data.created', false);
        $response = $this->guestOrder($table, $visitToken, [...$line, 'expectedTotal' => $shownTotal], 'price-new-round-001')
            ->assertStatus(409)->assertJsonPath('errors.reason', 'PRICE_CHANGED');
        $this->guestOrder($table, $visitToken, [...$line, 'expectedTotal' => $response->json('errors.currentTotal')], 'price-new-round-002')->assertCreated();
    }
}
