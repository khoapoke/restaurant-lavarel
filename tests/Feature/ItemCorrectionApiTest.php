<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderLineType;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\SessionEndType;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\ApprovalRequest;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceSession;
use App\Services\Admin\ReportService;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Staff\ApprovalService;
use App\Services\Staff\BillingService;
use App\Services\Staff\ItemCorrectionService;
use App\Services\Staff\KitchenService;
use App\Services\Staff\PosOrderService;
use App\Services\Staff\PosSessionService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Hủy / từ chối / làm lại / đổi / tách món và hoàn tác bước bếp (ADR-024).
 */
class ItemCorrectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
    }

    private function account(string $username): Account
    {
        return Account::where('ten_dang_nhap', $username)->firstOrFail();
    }

    private function token(string $username): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => '123'])->json('data.accessToken');
    }

    /** @return array{0: ServiceSession, 1: Order, 2: OrderItem} */
    private function sentItem(int $quantity = 2): array
    {
        $staff = $this->account('staff1');
        $table = DiningTable::where('ten_ban', 'Bàn T01')->firstOrFail();
        $session = app(PosSessionService::class)->open($staff, ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'correction-'.uniqid()])['session'];
        $order = app(PosOrderService::class)->createDraft($session, $staff, []);
        $dish = Dish::where('con_ban', true)->orderBy('ma_mon_an')->firstOrFail();
        $item = app(PosOrderService::class)->addItem($order, ['dishId' => $dish->ma_mon_an, 'quantity' => $quantity, 'clientRequestId' => 'correction-item-'.uniqid()]);
        app(PosOrderService::class)->sendKitchen($order, $staff, [$item->ma_chi_tiet]);

        return [$session->fresh(), $order->fresh(), $item->fresh()];
    }

    private function advance(OrderItem $item, KitchenItemStatus ...$steps): OrderItem
    {
        foreach ($steps as $step) {
            $item = app(KitchenService::class)->updateStatus($item, $step, $this->account('staff1'));
        }

        return $item->fresh();
    }

    public function test_staff_cancels_an_item_before_cooking_without_charge_and_the_open_invoice_is_reissued(): void
    {
        [$session, $order, $item] = $this->sentItem();
        $invoice = app(BillingService::class)->createInvoice($session);
        $this->assertSame(1, $invoice->phien_ban);

        $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/void", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason', 'note']);
        $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/void", [
            'reason' => 'ORDER_ENTRY_ERROR', 'note' => 'Nhân viên bấm nhầm món',
        ])->assertOk()
            ->assertJsonPath('data.kitchenStatus', 'CANCELLED')
            ->assertJsonPath('data.billedQuantity', 0)
            ->assertJsonPath('data.voidReason', 'ORDER_ENTRY_ERROR');

        $this->assertEquals(0, (float) $order->fresh()->tong_tien);
        $invoice->refresh();
        $this->assertSame(2, $invoice->phien_ban);
        $this->assertSame(InvoiceStatus::VOID, $invoice->versions()->where('phien_ban', 1)->first()->trang_thai);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'ITEM_VOIDED', 'ma_doi_tuong' => $item->ma_chi_tiet]);
    }

    public function test_staff_cannot_cancel_a_cooked_item_and_must_request_approval(): void
    {
        [, , $item] = $this->sentItem();
        $item = $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING);
        $token = $this->token('staff1');

        $this->withToken($token)->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/void", ['reason' => 'CUSTOMER_CHANGED_MIND', 'note' => 'Khách đổi ý'])
            ->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/waive", ['note' => 'Tự miễn tiền'])
            ->assertForbidden();

        $approvalId = $this->withToken($token)->postJson('/api/v1/staff/approvals', [
            'type' => 'ITEM_VOID', 'targetId' => $item->ma_chi_tiet, 'reason' => 'Khách đổi ý sau khi bếp nấu', 'payload' => ['reason' => 'CUSTOMER_CHANGED_MIND'],
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data.approvalId');
        // Gửi lại không tạo yêu cầu trùng.
        $this->withToken($token)->postJson('/api/v1/staff/approvals', [
            'type' => 'ITEM_VOID', 'targetId' => $item->ma_chi_tiet, 'reason' => 'Gửi lại', 'payload' => ['reason' => 'CUSTOMER_CHANGED_MIND'],
        ])->assertCreated()->assertJsonPath('data.approvalId', $approvalId);
        $this->withToken($token)->postJson("/api/v1/staff/approvals/{$approvalId}/approve")->assertForbidden();

        $this->assertSame(KitchenItemStatus::PREPARING, $item->fresh()->trang_thai_bep);
    }

    public function test_admin_approval_cancels_a_cooked_item_but_keeps_the_charge_when_the_customer_changed_mind(): void
    {
        [, $order, $item] = $this->sentItem();
        $item = $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING);
        $approval = app(ApprovalService::class)->request(ApprovalType::ITEM_VOID, $this->account('staff1'), $item->ma_chi_tiet, ['reason' => 'CUSTOMER_CHANGED_MIND'], 'Khách đổi ý');
        $before = (float) $order->fresh()->tong_tien;

        app(ApprovalService::class)->approve($approval, $this->account('admin'), 'Đồng ý, vẫn tính tiền');

        $item->refresh();
        $this->assertSame(KitchenItemStatus::CANCELLED, $item->trang_thai_bep);
        $this->assertSame((int) $item->so_luong, (int) $item->so_luong_tinh_tien);
        $this->assertEquals($before, (float) $order->fresh()->tong_tien);
        $this->assertSame(ApprovalStatus::APPROVED, $approval->fresh()->trang_thai);

        // Món hủy nhưng vẫn tính tiền: phiên không bị coi là rỗng, cũng không được hủy để né tiền.
        $session = ServiceSession::findOrFail($order->ma_phien);
        try {
            app(PosSessionService::class)->cancel($session, 'Thử hủy phiên');
            $this->fail('A session with billed items must not be cancelled');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $invoice = app(BillingService::class)->createInvoice($session);
        $this->assertEquals($before, (float) $invoice->tong_thanh_toan);
    }

    public function test_admin_can_waive_a_cooked_item_the_customer_no_longer_wants(): void
    {
        [, $order, $item] = $this->sentItem();
        $item = $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED);

        app(ItemCorrectionService::class)->void($item, $this->account('admin'), ['reason' => 'CUSTOMER_CHANGED_MIND', 'note' => 'Khách quen, quản lý miễn', 'waive' => true]);

        $item->refresh();
        $this->assertSame(0, (int) $item->so_luong_tinh_tien);
        $this->assertTrue($item->mien_tien);
        $this->assertNotNull($item->ma_nguoi_mien_tien);
        $this->assertEquals(0, (float) $order->fresh()->tong_tien);
        $this->assertGreaterThan(0, app(ReportService::class)->dashboard([])['finance']['waivedValue']);
    }

    public function test_restaurant_fault_on_a_cooked_item_is_never_charged(): void
    {
        [, $order, $item] = $this->sentItem();
        $item = $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED);

        app(ItemCorrectionService::class)->void($item, $this->account('admin'), ['reason' => 'DAMAGED', 'note' => 'Làm đổ khi mang ra']);

        $this->assertSame(0, (int) $item->fresh()->so_luong_tinh_tien);
        $this->assertFalse($item->fresh()->mien_tien);
        $this->assertEquals(0, (float) $order->fresh()->tong_tien);
    }

    public function test_kitchen_rejection_frees_the_session_and_can_mark_the_dish_sold_out(): void
    {
        [$session, $order, $item] = $this->sentItem();
        $item = $this->advance($item, KitchenItemStatus::QUEUED);
        $second = app(PosOrderService::class)->addItem($order, ['dishId' => Dish::where('con_ban', true)->where('ma_mon_an', '!=', $item->ma_mon_an)->value('ma_mon_an'), 'quantity' => 1, 'clientRequestId' => 'second-line-001']);
        app(PosOrderService::class)->sendKitchen($order->fresh(), $this->account('staff1'), [$second->ma_chi_tiet]);
        $this->advance($second->fresh(), KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY);

        $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/reject", [
            'reason' => 'OUT_OF_STOCK', 'note' => 'Hết bò tái', 'markSoldOut' => true,
        ])->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->assertFalse(Dish::find($item->ma_mon_an)->con_ban);
        $order->refresh();
        $this->assertEquals((float) $second->don_gia, (float) $order->tong_tien);

        $invoice = app(BillingService::class)->createInvoice($session->fresh());
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $invoice->tong_thanh_toan, 'idempotencyKey' => 'cash-after-reject-01'], $this->account('staff1')->ma_tai_khoan);
        $closed = app(BillingService::class)->close($session->fresh(), $session->fresh()->phien_ban);
        $this->assertSame(PosSessionStatus::CLOSED, $closed->trang_thai);
    }

    public function test_a_remake_is_free_and_linked_to_the_original_line(): void
    {
        [, $order, $item] = $this->sentItem(1);
        $item = $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED);
        $total = (float) $order->fresh()->tong_tien;

        $remakeId = $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/remake", [
            'reason' => 'RESTAURANT_MISTAKE', 'note' => 'Món mặn',
        ])->assertCreated()
            ->assertJsonPath('data.lineType', 'REMAKE')
            ->assertJsonPath('data.billedQuantity', 0)
            ->assertJsonPath('data.originalItemId', $item->ma_chi_tiet)
            ->json('data.itemId');

        $this->assertEquals($total, (float) $order->fresh()->tong_tien);
        $this->assertTrue(app(KitchenService::class)->activeItems()->contains('ma_chi_tiet', $remakeId));
        $this->assertSame(OrderLineType::REMAKE, OrderItem::find($remakeId)->loai_dong);
    }

    public function test_exchange_keeps_the_old_snapshot_and_adds_a_linked_draft(): void
    {
        [, $order, $item] = $this->sentItem(1);
        $newDish = Dish::where('con_ban', true)->where('ma_mon_an', '!=', $item->ma_mon_an)->firstOrFail();

        $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/exchange", [
            'dishId' => $newDish->ma_mon_an, 'quantity' => 1, 'reason' => 'CUSTOMER_CHANGED_MIND', 'note' => 'Khách muốn đổi món',
        ])->assertCreated()
            ->assertJsonPath('data.kitchenStatus', 'DRAFT')
            ->assertJsonPath('data.lineType', 'EXCHANGE')
            ->assertJsonPath('data.originalItemId', $item->ma_chi_tiet);

        $old = $item->fresh();
        $this->assertSame(KitchenItemStatus::CANCELLED, $old->trang_thai_bep);
        $this->assertSame($item->ten_mon_snapshot, $old->ten_mon_snapshot);
        $this->assertEquals((float) $newDish->gia_tien, (float) $order->fresh()->tong_tien);
    }

    public function test_splitting_a_line_preserves_quantity_and_amount(): void
    {
        [, $order, $item] = $this->sentItem(3);
        $total = (float) $order->fresh()->tong_tien;

        $partId = $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/split", ['quantity' => 1])
            ->assertCreated()->assertJsonPath('data.quantity', 1)->json('data.itemId');
        $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/order-items/{$item->ma_chi_tiet}/split", ['quantity' => 2])
            ->assertUnprocessable();

        $this->assertSame(2, (int) $item->fresh()->so_luong);
        $this->assertSame(3, (int) OrderItem::where('ma_don_hang', $order->ma_don_hang)->sum('so_luong'));
        $this->assertEquals($total, (float) $order->fresh()->tong_tien);

        // Phục vụ trước phần đã tách; phần còn lại vẫn đang chờ.
        $part = $this->advance(OrderItem::find($partId), KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED);
        $this->assertSame(KitchenItemStatus::SERVED, $part->trang_thai_bep);
        $this->assertSame(KitchenItemStatus::SENT, $item->fresh()->trang_thai_bep);
    }

    public function test_a_kitchen_step_can_be_undone_only_within_the_window(): void
    {
        [, , $item] = $this->sentItem();
        $item = $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING);
        $token = $this->token('staff1');

        $this->withToken($token)->postJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/undo")
            ->assertOk()->assertJsonPath('data.status', 'QUEUED');
        $this->withToken($token)->postJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/undo")->assertStatus(409);

        $this->advance($item->fresh(), KitchenItemStatus::PREPARING);
        $this->travel(121)->seconds();
        $this->withToken($token)->postJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/undo")->assertStatus(409);
        $this->assertSame(KitchenItemStatus::PREPARING, $item->fresh()->trang_thai_bep);
    }

    public function test_voiding_after_payment_leaves_the_paid_invoice_and_shows_a_refund_due(): void
    {
        [$session, $order, $item] = $this->sentItem(2);
        $invoice = app(BillingService::class)->createInvoice($session);
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $invoice->tong_thanh_toan, 'idempotencyKey' => 'cash-before-void-01'], $this->account('staff1')->ma_tai_khoan);
        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->trang_thai);

        app(ItemCorrectionService::class)->reject($item->fresh(), $this->account('staff1'), ['reason' => 'OUT_OF_STOCK', 'note' => 'Hết nguyên liệu sau khi khách đã trả']);

        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->trang_thai);
        $this->assertEquals((float) $invoice->tong_thanh_toan, app(PaymentLedgerService::class)->refundDue($order->fresh()));
        $this->assertSame(PaymentStatus::PAID, $order->payment()->firstOrFail()->trang_thai);
    }

    public function test_guest_who_walks_out_is_written_off_only_by_an_admin_and_never_marked_paid(): void
    {
        [$session, $order, $item] = $this->sentItem(2);
        $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY, KitchenItemStatus::SERVED);
        $invoice = app(BillingService::class)->createInvoice($session);
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => 10000, 'idempotencyKey' => 'partial-cash-wo-01'], $this->account('staff1')->ma_tai_khoan);
        $total = (float) $order->fresh()->tong_tien;

        $this->withToken($this->token('staff1'))->postJson("/api/v1/staff/pos/sessions/{$session->ma_phien}/write-off", ['note' => 'Khách bỏ đi'])->assertForbidden();
        $approval = app(ApprovalService::class)->request(ApprovalType::SESSION_WRITE_OFF, $this->account('staff1'), $session->ma_phien, [], 'Khách rời đi khi chưa thanh toán phần còn lại');

        app(ApprovalService::class)->approve($approval, $this->account('admin'), 'Đã xem camera, xác nhận');

        $session->refresh();
        $this->assertSame(PosSessionStatus::CLOSED, $session->trang_thai);
        $this->assertSame(SessionEndType::WRITE_OFF, $session->kieu_ket_thuc);
        $this->assertEquals($total - 10000, (float) $session->so_tien_that_thu);
        $this->assertSame(InvoiceStatus::WRITTEN_OFF, Invoice::find($invoice->ma_hoa_don)->trang_thai);
        $this->assertSame(PaymentStatus::PARTIALLY_PAID, $order->payment()->firstOrFail()->trang_thai);
        $this->assertSame(TableStatus::CLEANING, DiningTable::where('ten_ban', 'Bàn T01')->first()->trang_thai);

        $finance = app(ReportService::class)->dashboard([])['finance'];
        $this->assertEquals($total - 10000, $finance['writeOffValue']);
    }

    public function test_write_off_rolls_back_completely_when_nothing_is_owed(): void
    {
        [$session, , $item] = $this->sentItem(1);
        $this->advance($item, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY);
        $invoice = app(BillingService::class)->createInvoice($session);
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $invoice->tong_thanh_toan, 'idempotencyKey' => 'paid-full-wo-01'], $this->account('staff1')->ma_tai_khoan);

        try {
            app(BillingService::class)->writeOff($session->fresh(), $this->account('admin'), 'Thử thất thu');
            $this->fail('write-off should be refused');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(PosSessionStatus::AWAITING_PAYMENT, $session->fresh()->trang_thai);
        $this->assertSame(KitchenItemStatus::READY, $item->fresh()->trang_thai_bep);
        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->trang_thai);
        $this->assertSame(0, ApprovalRequest::count());
    }
}
