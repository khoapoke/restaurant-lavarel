<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\InvoiceStatus;
use App\Enums\MoneyFlag;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\Invoice;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ServiceSession;
use App\Services\Admin\OrderService as AdminOrderService;
use App\Services\Admin\ReportService;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Staff\BillingService;
use App\Services\Staff\PosOrderService;
use App\Services\Staff\PosSessionService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Sổ giao dịch tiền (ADR-023): mỗi khoản tiền thực nhận có bản ghi riêng, không áp vượt,
 * không mất dấu khoản thứ hai/dư/thiếu/QR cũ, hoàn tiền có chứng từ, báo cáo theo ngày nhận.
 */
class PaymentLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ledger-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
        config(['services.payment_webhook.secret' => self::SECRET]);
    }

    private function qrOrder(float $total = 150000): Order
    {
        $order = Order::create([
            'ngay_dat_hang' => now(),
            'tong_tien' => $total,
            'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
            'ten_nguoi_nhan' => 'Khách sổ giao dịch',
            'so_dien_thoai_nhan' => '0901234567',
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'),
            'kenh' => OrderChannel::ONLINE,
            'loai_phuc_vu' => FulfillmentType::PICKUP,
            'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
        ]);
        Payment::create(['so_tien' => $total, 'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR, 'trang_thai' => PaymentStatus::UNPAID, 'ma_don_hang' => $order->ma_don_hang]);

        return $order;
    }

    /** @param array<string, mixed> $payload */
    private function webhook(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => '123'])->json('data.accessToken');
    }

    private function staff(): Account
    {
        return Account::where('ten_dang_nhap', 'staff1')->firstOrFail();
    }

    /** @return array{0: ServiceSession, 1: Invoice, 2: Order} */
    private function invoicedSession(int $quantity = 2): array
    {
        $table = DiningTable::where('ten_ban', 'Bàn T01')->firstOrFail();
        $session = app(PosSessionService::class)->open($this->staff(), ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'ledger-session-'.uniqid()])['session'];
        $order = app(PosOrderService::class)->createDraft($session, $this->staff(), []);
        $dish = Dish::where('con_ban', true)->orderBy('ma_mon_an')->firstOrFail();
        $item = app(PosOrderService::class)->addItem($order, ['dishId' => $dish->ma_mon_an, 'quantity' => $quantity, 'clientRequestId' => 'ledger-item-'.uniqid()]);
        app(PosOrderService::class)->sendKitchen($order, $this->staff(), [$item->ma_chi_tiet]);
        $invoice = app(BillingService::class)->createInvoice($session);

        return [$session->fresh(), $invoice->fresh(), $order->fresh()];
    }

    /** @return array<string, mixed> */
    private function finance(): array
    {
        return app(ReportService::class)->dashboard([])['finance'];
    }

    public function test_a_second_transfer_for_a_paid_order_is_recorded_separately_and_flagged(): void
    {
        $order = $this->qrOrder();
        $before = $this->finance();

        $this->webhook(['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => 150000, 'transactionCode' => 'FT-FIRST-001'])
            ->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->webhook(['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => 150000, 'transactionCode' => 'FT-SECOND-002'])
            ->assertOk()
            ->assertJsonPath('data.flag', 'DUPLICATE')
            ->assertJsonPath('data.reconciliation', 'NEEDS_REVIEW');

        $this->assertSame(2, MoneyTransaction::where('ma_don_hang_muc_tieu', $order->ma_don_hang)->count());
        $payment = $order->payment()->firstOrFail();
        $this->assertSame(PaymentStatus::PAID, $payment->trang_thai);
        $this->assertEquals(150000, (float) $payment->so_tien_da_thu);

        $second = MoneyTransaction::where('ma_tham_chieu', 'FT-SECOND-002')->firstOrFail();
        $this->assertEquals(150000, $second->unallocatedAmount());

        $after = $this->finance();
        $this->assertEquals(300000, $after['moneyReceived'] - $before['moneyReceived'], 'Both transfers are money received');
        $this->assertEquals(150000, $after['appliedToSales'] - $before['appliedToSales'], 'Only one transfer pays for the sale');
        $this->assertEquals(150000, $after['unallocatedBalance'] - $before['unallocatedBalance']);
    }

    public function test_the_same_callback_delivered_twice_does_not_create_money(): void
    {
        $order = $this->qrOrder();
        $payload = ['content' => "DH{$order->ma_don_hang} THANH TOAN", 'transferAmount' => 150000, 'transactionCode' => 'FT-RETRY-001'];

        $this->webhook($payload)->assertOk()->assertJsonPath('data.duplicateCallback', false);
        $this->webhook($payload)->assertOk()->assertJsonPath('data.duplicateCallback', true)->assertJsonPath('data.status', 'DUPLICATE_CALLBACK');

        $this->assertSame(1, MoneyTransaction::where('ma_tham_chieu', 'FT-RETRY-001')->count());
        $this->assertEquals(150000, (float) $order->payment()->value('so_tien_da_thu'));
    }

    public function test_the_same_reference_at_different_banks_is_two_different_transfers(): void
    {
        $order = $this->qrOrder(300000);

        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'REF-001', 'gateway' => 'VCB'])->assertOk();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'REF-001', 'gateway' => 'MB'])->assertOk();

        $this->assertSame(2, MoneyTransaction::where('ma_tham_chieu', 'REF-001')->count());
        $this->assertSame(PaymentStatus::PAID, $order->payment()->firstOrFail()->trang_thai);
    }

    public function test_overpaid_transfer_applies_only_the_order_amount_and_keeps_the_rest_for_refund(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 200000, 'transactionCode' => 'FT-OVER-001'])
            ->assertOk()
            ->assertJsonPath('data.appliedAmount', fn ($value) => (float) $value === 150000.0)
            ->assertJsonPath('data.flag', 'OVERPAID');

        $transaction = MoneyTransaction::where('ma_tham_chieu', 'FT-OVER-001')->firstOrFail();
        $this->assertEquals(50000, $transaction->unallocatedAmount());
        $this->assertSame(PaymentStatus::PAID, $order->payment()->firstOrFail()->trang_thai);

        // Hoàn 60.000 ₫: 50.000 ₫ là phần dư, 10.000 ₫ vượt số nhà hàng đang nợ → phải ghi là bồi hoàn.
        $body = ['amount' => 60000, 'method' => 'BANK_TRANSFER', 'referenceCode' => 'HOAN-OVER-1', 'refundReason' => 'Hoàn phần chuyển dư và bồi hoàn'];
        $this->withToken($this->adminToken())->postJson("/api/v1/admin/payments/transactions/{$transaction->ma_giao_dich_tien}/refunds", $body)
            ->assertUnprocessable();
        $this->assertEquals(50000, $transaction->fresh()->unallocatedAmount());
        $this->assertSame(0, Refund::count());

        $this->withToken($this->adminToken())->postJson("/api/v1/admin/payments/transactions/{$transaction->ma_giao_dich_tien}/refunds", [...$body, 'refundType' => 'COMPENSATION'])
            ->assertOk();

        $this->assertEquals(0, $transaction->fresh()->unallocatedAmount());
        // Một yêu cầu, hai dòng chi tiết cùng khóa: 50.000 ₫ tiền dư (hoàn) + 10.000 ₫ rút từ đơn (bồi hoàn).
        $rows = Refund::where('ma_chung_tu', 'HOAN-OVER-1')->orderBy('so_tien_tu_phan_bo')->get();
        $this->assertCount(1, $rows->pluck('khoa_yeu_cau')->unique());
        $this->assertEquals(60000, (float) $rows->sum('so_tien'));
        $this->assertEquals([50000.0, 0.0, Refund::TYPE_REFUND], [(float) $rows[0]->so_tien, (float) $rows[0]->so_tien_tu_phan_bo, $rows[0]->loai_hoan]);
        $this->assertEquals([10000.0, 10000.0, Refund::TYPE_COMPENSATION], [(float) $rows[1]->so_tien, (float) $rows[1]->so_tien_tu_phan_bo, $rows[1]->loai_hoan]);
        $payment = $order->payment()->firstOrFail();
        // Khoản phải thu giảm còn 140.000 ₫: đơn đã trả xong, có hoàn một phần, không "còn thiếu" giả.
        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED, $payment->trang_thai);
        $this->assertEquals(140000, (float) $payment->so_tien_da_thu);
        $this->assertEquals(10000, (float) $order->fresh()->so_tien_giam_tru);
        $this->assertSame(0.0, app(PaymentLedgerService::class)->outstanding($order->fresh()));
    }

    public function test_refund_of_the_surplus_resolves_the_transaction_and_keeps_the_order_paid(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 200000, 'transactionCode' => 'FT-OVER-002'])->assertOk();
        $transaction = MoneyTransaction::where('ma_tham_chieu', 'FT-OVER-002')->firstOrFail();

        $this->withToken($this->adminToken())->postJson("/api/v1/admin/payments/transactions/{$transaction->ma_giao_dich_tien}/refunds", [
            'amount' => 50000, 'method' => 'BANK_TRANSFER', 'referenceCode' => 'HOAN-SURPLUS', 'refundReason' => 'Hoàn phần chuyển dư',
        ])->assertOk()
            ->assertJsonPath('data.reconciliation', 'RESOLVED')
            ->assertJsonPath('data.unallocatedAmount', fn ($value) => (float) $value === 0.0)
            ->assertJsonPath('data.refunds.0.reference', 'HOAN-SURPLUS');

        $refund = Refund::firstWhere('ma_chung_tu', 'HOAN-SURPLUS');
        $this->assertNotNull($refund->ma_nguoi_thuc_hien);
        $this->assertNotNull($refund->hoan_luc);
        $this->assertEquals(0, (float) $refund->so_tien_tu_phan_bo);
        $this->assertSame(PaymentStatus::PAID, $order->payment()->firstOrFail()->trang_thai);
    }

    public function test_partial_refunds_cannot_exceed_what_was_received(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'FT-REFUND-001'])->assertOk();
        $payment = $order->payment()->firstOrFail();
        $token = $this->adminToken();
        $refund = fn (float $amount, string $reference) => $this->withToken($token)->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/refund", [
            'amount' => $amount, 'method' => 'BANK_TRANSFER', 'referenceCode' => $reference, 'refundReason' => 'Bồi hoàn theo yêu cầu', 'refundType' => 'COMPENSATION',
        ]);

        $refund(50000, 'HOAN-1')->assertOk()->assertJsonPath('data.status', 'PARTIALLY_REFUNDED')->assertJsonPath('data.outstanding', fn ($value) => (float) $value === 0.0);
        $refund(120000, 'HOAN-2')->assertStatus(409);
        $refund(100000, 'HOAN-3')->assertOk()->assertJsonPath('data.status', 'REFUNDED');
        $refund(1, 'HOAN-4')->assertStatus(400);

        $this->assertEquals(150000, (float) Refund::where('ma_don_hang', $order->ma_don_hang)->sum('so_tien'));
        $this->assertEquals(150000, (float) $payment->fresh()->so_tien_da_hoan);
    }

    public function test_money_for_a_cancelled_order_is_kept_for_refund_and_not_counted_as_sales(): void
    {
        $order = $this->qrOrder();
        $order->update(['trang_thai' => OrderStatus::CANCELLED]);
        $before = $this->finance();

        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'FT-CANCELLED-1'])
            ->assertOk()
            ->assertJsonPath('data.flag', 'ORDER_CANCELLED')
            ->assertJsonPath('data.appliedAmount', fn ($value) => (float) $value === 0.0);

        $this->assertSame(PaymentStatus::UNPAID, $order->payment()->firstOrFail()->trang_thai);
        $after = $this->finance();
        $this->assertEquals($before['appliedToSales'], $after['appliedToSales']);
        $this->assertEquals(150000, $after['moneyReceived'] - $before['moneyReceived']);
        $this->assertEquals(150000, $after['unallocatedBalance'] - $before['unallocatedBalance']);
    }

    public function test_paid_then_cancelled_order_moves_from_sales_to_refund_due(): void
    {
        $order = $this->qrOrder();
        $before = $this->finance();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'FT-PAID-CANCEL'])->assertOk();
        $this->assertEquals(150000, $this->finance()['appliedToSales'] - $before['appliedToSales']);
        app(AdminOrderService::class)->cancel($order->ma_don_hang, 'Hết nguyên liệu chính');

        $dashboard = app(ReportService::class)->dashboard([]);
        $this->assertEquals($before['appliedToSales'], $dashboard['finance']['appliedToSales']);
        $this->assertEquals(150000, $dashboard['finance']['refundDueBalance'] - $before['refundDueBalance']);
        $this->assertSame(1, $dashboard['attention']['refundsPending']);
    }

    public function test_a_callback_without_a_bank_reference_waits_for_review(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000])
            ->assertOk()
            ->assertJsonPath('data.flag', 'NO_REFERENCE')
            ->assertJsonPath('data.status', 'RECORDED_FOR_REVIEW');

        $this->assertSame(PaymentStatus::UNPAID, $order->payment()->firstOrFail()->trang_thai);
        $this->assertDatabaseHas('GIAO_DICH_TIEN', ['ma_don_hang_muc_tieu' => $order->ma_don_hang, 'trang_thai_doi_soat' => ReconciliationStatus::NEEDS_REVIEW->value]);
    }

    public function test_an_unknown_target_is_recorded_for_review(): void
    {
        $this->webhook(['content' => 'CHUYEN TIEN AN TRUA', 'amount' => 99000, 'transactionCode' => 'FT-UNKNOWN-1'])
            ->assertOk()->assertJsonPath('data.flag', 'UNKNOWN_TARGET');

        $this->assertDatabaseHas('GIAO_DICH_TIEN', ['ma_tham_chieu' => 'FT-UNKNOWN-1', 'canh_bao' => MoneyFlag::UNKNOWN_TARGET->value]);
    }

    public function test_old_invoice_qr_after_reissue_is_recorded_and_can_be_applied_by_an_admin(): void
    {
        [$session, $invoice, $order] = $this->invoicedSession();
        $this->assertSame(1, $invoice->phien_ban);
        app(BillingService::class)->reopen($session);
        $reissued = app(BillingService::class)->createInvoice($session->fresh());
        $this->assertSame(2, $reissued->phien_ban);
        $this->assertSame(InvoiceStatus::VOID, $reissued->versions()->where('phien_ban', 1)->first()->trang_thai);

        $this->webhook(['content' => "HD{$invoice->ma_hoa_don}V1 THANH TOAN", 'amount' => (float) $order->tong_tien, 'transactionCode' => 'FT-OLD-QR-1'])
            ->assertOk()->assertJsonPath('data.flag', 'INVOICE_STALE');
        $this->webhook(['content' => "HD{$invoice->ma_hoa_don} THANH TOAN", 'amount' => 1000, 'transactionCode' => 'FT-OLD-QR-LEGACY'])
            ->assertOk()->assertJsonPath('data.flag', 'INVOICE_STALE');
        $this->assertSame(InvoiceStatus::OPEN, $reissued->fresh()->trang_thai);

        $stale = MoneyTransaction::where('ma_tham_chieu', 'FT-OLD-QR-1')->firstOrFail();
        $this->withToken($this->adminToken())->postJson("/api/v1/admin/payments/transactions/{$stale->ma_giao_dich_tien}/allocate", [
            'orderId' => $order->ma_don_hang, 'note' => 'Khách quét QR cũ, đã đối chiếu với nhân viên',
        ])->assertOk()->assertJsonPath('data.reconciliation', 'RESOLVED');

        $this->assertSame(InvoiceStatus::PAID, $reissued->fresh()->trang_thai);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'PAYMENT_ALLOCATED_MANUALLY', 'ma_doi_tuong' => $stale->ma_giao_dich_tien]);
    }

    public function test_money_arriving_while_the_invoice_is_voided_is_recorded(): void
    {
        [$session, $invoice, $order] = $this->invoicedSession();
        app(BillingService::class)->reopen($session);

        $this->webhook(['content' => "HD{$invoice->ma_hoa_don}V1 THANH TOAN", 'amount' => (float) $order->tong_tien, 'transactionCode' => 'FT-VOIDED-1'])
            ->assertOk()->assertJsonPath('data.flag', 'INVOICE_STALE');

        $this->assertDatabaseHas('GIAO_DICH_TIEN', ['ma_tham_chieu' => 'FT-VOIDED-1', 'trang_thai_doi_soat' => ReconciliationStatus::NEEDS_REVIEW->value]);
    }

    public function test_underpaid_invoice_shows_the_shortfall_and_cash_can_top_it_up(): void
    {
        [$session, $invoice, $order] = $this->invoicedSession();
        $total = (float) $order->tong_tien;

        $this->webhook(['content' => "HD{$invoice->ma_hoa_don}V1 THANH TOAN", 'amount' => $total - 20000, 'transactionCode' => 'FT-SHORT-INV'])
            ->assertOk()->assertJsonPath('data.flag', 'UNDERPAID');
        $this->assertSame(InvoiceStatus::OPEN, $invoice->fresh()->trang_thai);

        $token = $this->postJson('/api/v1/auth/login', ['username' => 'staff1', 'password' => '123'])->json('data.accessToken');
        $this->withToken($token)->getJson("/api/v1/staff/pos/sessions/{$session->ma_phien}")
            ->assertJsonPath('data.invoice.outstanding', fn ($value) => (float) $value === 20000.0)
            ->assertJsonPath('data.invoice.qr.amount', fn ($value) => $value === null || (float) $value === 20000.0);

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'cash-topup-0001'])
            ->postJson("/api/v1/staff/invoices/{$invoice->ma_hoa_don}/payments", ['method' => 'CASH', 'amount' => 30000])
            ->assertStatus(409);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'cash-topup-0002'])
            ->postJson("/api/v1/staff/invoices/{$invoice->ma_hoa_don}/payments", ['method' => 'CASH', 'amount' => 20000, 'cashTendered' => 50000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PAID');

        $this->assertSame(2, MoneyTransaction::whereHas('allocations', fn ($query) => $query->where('ma_don_hang', $order->ma_don_hang))->count());
    }

    public function test_one_bank_reference_cannot_pay_two_orders(): void
    {
        $first = $this->qrOrder();
        $second = $this->qrOrder();
        $token = $this->adminToken();

        $this->withToken($token)->postJson("/api/v1/admin/payments/{$first->payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT-SHARED-99', 'amount' => 150000])
            ->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->withToken($token)->postJson("/api/v1/admin/payments/{$second->payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT-SHARED-99', 'amount' => 150000])
            ->assertStatus(409);

        $this->assertSame(PaymentStatus::UNPAID, $second->payment()->firstOrFail()->trang_thai);
        $this->assertSame(1, MoneyTransaction::where('ma_tham_chieu', 'FT-SHARED-99')->count());
    }

    public function test_manual_confirmation_merges_with_a_callback_for_the_same_transfer(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'FT-MERGE-1'])->assertOk();

        $this->withToken($this->adminToken())->postJson("/api/v1/admin/payments/{$order->payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT-MERGE-1', 'amount' => 150000])
            ->assertStatus(409);

        $this->assertSame(1, MoneyTransaction::where('ma_tham_chieu', 'FT-MERGE-1')->count());
        $this->assertEquals(150000, (float) $order->payment()->value('so_tien_da_thu'));
    }

    public function test_pos_transfer_reference_already_received_by_webhook_is_merged_not_doubled(): void
    {
        [, $invoice, $order] = $this->invoicedSession();
        $this->webhook(['content' => 'KHONG RO NOI DUNG', 'amount' => (float) $order->tong_tien, 'transactionCode' => 'FT-POS-MERGE', 'accountNumber' => config('services.vietqr.account_no')])->assertOk();

        app(BillingService::class)->pay($invoice, ['method' => 'BANK_TRANSFER_QR', 'amount' => (float) $order->tong_tien, 'transactionCode' => 'FT-POS-MERGE', 'idempotencyKey' => 'pos-merge-000001'], $this->staff()->ma_tai_khoan);

        $this->assertSame(1, MoneyTransaction::where('ma_tham_chieu', 'FT-POS-MERGE')->count());
        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->trang_thai);
    }

    public function test_pos_transfer_requires_a_real_bank_reference(): void
    {
        [, $invoice, $order] = $this->invoicedSession();

        $this->expectException(HttpException::class);
        app(BillingService::class)->pay($invoice, ['method' => 'BANK_TRANSFER_QR', 'amount' => (float) $order->tong_tien, 'transactionCode' => 'x', 'idempotencyKey' => 'pos-short-ref-01'], $this->staff()->ma_tai_khoan);
    }

    public function test_report_counts_money_by_the_day_it_was_received_and_refunds_by_the_day_they_were_made(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'FT-DAY-1', 'transactionDate' => now()->subDays(3)->toDateTimeString()])->assertOk();
        $this->withToken($this->adminToken())->postJson("/api/v1/admin/payments/{$order->payment->ma_thanh_toan}/refund", [
            'amount' => 40000, 'method' => 'CASH', 'referenceCode' => 'PHIEU-CHI-1', 'refundReason' => 'Bồi hoàn món thiếu', 'refundType' => 'COMPENSATION',
        ])->assertOk();

        $threeDaysAgo = now()->subDays(3)->toDateString();
        $finance = app(ReportService::class)->dashboard(['fromDate' => $threeDaysAgo, 'toDate' => $threeDaysAgo])['finance'];
        $this->assertEquals(150000, $finance['moneyReceived']);
        $this->assertEquals(0, $finance['refunded']);

        $today = app(ReportService::class)->dashboard(['fromDate' => now()->toDateString(), 'toDate' => now()->toDateString()])['finance'];
        $this->assertEquals(40000, $today['refunded']);
        $this->assertSame(150000.0 - 40000.0, (float) $order->payment()->value('so_tien_da_thu'));
    }
}
