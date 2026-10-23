<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\MoneyFlag;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Admin\ReportService;
use App\Services\Payments\PaymentLedgerService;
use App\Services\Staff\BillingService;
use App\Services\Staff\KitchenService;
use App\Services\Staff\PosOrderService;
use App\Services\Staff\PosSessionService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nghiệm thu các phát hiện review sổ tiền (A, B, C, F): định danh chuyển khoản theo ngân hàng/tài khoản
 * nhận thật, webhook có đích không tồn tại, gửi lại yêu cầu hoàn tiền, bồi hoàn sau thanh toán tại POS.
 */
class PaymentReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'review-regression-secret';

    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        config([
            'services.payment_webhook.secret' => self::SECRET,
            'services.vietqr.bank_bin' => '970422',
            'services.vietqr.account_no' => '0123456789',
        ]);
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

    /** @param array<string, mixed> $payload */
    private function webhook(array $payload): TestResponse
    {
        $this->flushHeaders();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function qrOrder(float $total = 150000): Order
    {
        $order = Order::create([
            'ngay_dat_hang' => now(), 'tong_tien' => $total, 'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
            'ten_nguoi_nhan' => 'Khách review', 'so_dien_thoai_nhan' => '0901234567',
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'),
            'kenh' => OrderChannel::ONLINE, 'loai_phuc_vu' => FulfillmentType::PICKUP, 'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
        ]);
        Payment::create(['so_tien' => $total, 'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR, 'trang_thai' => PaymentStatus::UNPAID, 'ma_don_hang' => $order->ma_don_hang]);

        return $order;
    }

    // A ---------------------------------------------------------------------------------------------

    public function test_a_manual_confirmation_and_the_bank_webhook_for_the_same_transfer_are_one_receipt(): void
    {
        $order = $this->qrOrder();
        $this->as('admin')->postJson("/api/v1/admin/payments/{$order->payment->ma_thanh_toan}/confirm", ['transactionCode' => 'FT26REVIEW001', 'amount' => 150000])->assertOk();

        // Ngân hàng báo về sau với nhãn gateway riêng và số tài khoản nhận đã cấu hình.
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'gateway' => 'MBBank', 'accountNumber' => '0123456789', 'transactionCode' => 'FT26REVIEW001'])
            ->assertOk()->assertJsonPath('data.duplicateCallback', true);
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'gateway' => 'MB', 'transactionCode' => 'ft26review001'])
            ->assertOk()->assertJsonPath('data.duplicateCallback', true);

        $this->assertSame(1, MoneyTransaction::where('ma_tham_chieu', 'like', 'FT26REVIEW001')->count() + MoneyTransaction::where('ma_tham_chieu', 'ft26review001')->count());
        $this->assertEquals(150000, (float) $order->payment()->value('so_tien_da_thu'));
    }

    public function test_a_the_same_reference_in_another_bank_or_account_is_a_different_transfer(): void
    {
        $first = $this->qrOrder();
        $second = $this->qrOrder();
        $this->webhook(['orderId' => $first->ma_don_hang, 'amount' => 150000, 'gateway' => 'MBBank', 'accountNumber' => '0123456789', 'transactionCode' => 'REF-SHARED-01'])->assertOk();
        // Cùng mã ở tài khoản khác (ngân hàng khác): khoản tiền khác, không bị coi là gửi lại.
        $this->webhook(['orderId' => $second->ma_don_hang, 'amount' => 150000, 'gateway' => 'Vietcombank', 'accountNumber' => '9988776655', 'transactionCode' => 'REF-SHARED-01'])
            ->assertOk()->assertJsonPath('data.duplicateCallback', false);

        $this->assertSame(2, MoneyTransaction::where('ma_tham_chieu', 'REF-SHARED-01')->count());
        $this->assertEquals(150000, (float) $second->payment()->value('so_tien_da_thu'));
    }

    // B ---------------------------------------------------------------------------------------------

    public function test_b_a_signed_receipt_for_a_missing_order_or_invoice_is_stored_for_review(): void
    {
        foreach (['DH999999', 'HD999999V1'] as $content) {
            $reference = 'UNKNOWN-'.$content;
            $this->webhook(['content' => $content.' THANH TOAN', 'amount' => 150000, 'transactionCode' => $reference])
                ->assertOk()
                ->assertJsonPath('data.status', 'RECORDED_FOR_REVIEW')
                ->assertJsonPath('data.flag', 'UNKNOWN_TARGET');

            $transaction = MoneyTransaction::where('ma_tham_chieu', $reference)->sole();
            $this->assertNull($transaction->ma_don_hang_muc_tieu);
            $this->assertNull($transaction->ma_hoa_don_muc_tieu);
            $this->assertSame(ReconciliationStatus::NEEDS_REVIEW, $transaction->trang_thai_doi_soat);
            $this->assertStringContainsString($content, (string) $transaction->noi_dung);
            $this->assertEquals(150000, $transaction->unallocatedAmount());
        }
    }

    // C ---------------------------------------------------------------------------------------------

    public function test_c_resending_the_same_refund_request_or_voucher_does_not_refund_twice(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 200000, 'transactionCode' => 'FT-REFUND-REPLAY'])->assertOk();
        $payment = $order->payment()->firstOrFail();
        $body = ['amount' => 10000, 'method' => 'CASH', 'referenceCode' => 'PC-0001', 'refundReason' => 'Hoàn phần chuyển dư'];

        $transaction = MoneyTransaction::where('ma_tham_chieu', 'FT-REFUND-REPLAY')->sole();
        $send = fn (array $payload, string $key) => $this->as('admin')->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/admin/payments/transactions/{$transaction->ma_giao_dich_tien}/refunds", $payload);

        $send($body, 'refund-key-0001')->assertOk();
        $send($body, 'refund-key-0001')->assertOk(); // mất phản hồi, bấm lại cùng khóa
        $send($body, 'refund-key-0002')->assertOk(); // khóa mới nhưng cùng chứng từ, cùng số tiền
        $send([...$body, 'amount' => 20000], 'refund-key-0003')->assertStatus(409); // dùng lại chứng từ cho khoản khác

        $this->assertSame(1, Refund::count());
        $this->assertEquals(10000, (float) $transaction->fresh()->so_tien_da_hoan);
        $this->assertEquals(40000, $transaction->fresh()->unallocatedAmount());
        $this->assertEquals(150000, (float) $payment->fresh()->so_tien_da_thu);
    }

    public function test_c_one_order_refund_split_across_two_transfers_is_replayed_as_a_whole(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 100000, 'transactionCode' => 'FT-SPLIT-1'])->assertOk();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 50000, 'transactionCode' => 'FT-SPLIT-2'])->assertOk();
        $order->update(['trang_thai' => OrderStatus::CANCELLED]);
        app(PaymentLedgerService::class)->syncOrderPayment($order->fresh());
        $body = ['amount' => 150000, 'method' => 'BANK_TRANSFER', 'referenceCode' => 'HT-ORDER-01', 'refundReason' => 'Đơn hủy, hoàn toàn bộ'];

        $this->as('admin')->withHeader('Idempotency-Key', 'order-refund-01')->postJson("/api/v1/admin/payments/{$order->payment->ma_thanh_toan}/refund", $body)
            ->assertOk()->assertJsonPath('data.status', 'REFUNDED');
        $this->as('admin')->withHeader('Idempotency-Key', 'order-refund-01')->postJson("/api/v1/admin/payments/{$order->payment->ma_thanh_toan}/refund", $body)
            ->assertOk()->assertJsonPath('data.status', 'REFUNDED');

        // Một yêu cầu hoàn trải trên hai giao dịch: hai dòng cùng chứng từ, gửi lại không thêm dòng.
        $this->assertSame(2, Refund::where('ma_chung_tu', 'HT-ORDER-01')->count());
        $this->assertEquals(150000, (float) Refund::sum('so_tien'));
    }

    // F ---------------------------------------------------------------------------------------------

    public function test_f_a_partial_compensation_after_payment_keeps_the_pos_session_closable(): void
    {
        $staff = Account::where('ten_dang_nhap', 'staff1')->firstOrFail();
        $table = DiningTable::where('ten_ban', 'Bàn T01')->firstOrFail();
        $session = app(PosSessionService::class)->open($staff, ['tableIds' => [$table->ma_ban], 'guestCount' => 2, 'idempotencyKey' => 'review-f-session'])['session'];
        $order = app(PosOrderService::class)->createDraft($session, $staff, []);
        $dish = Dish::where('con_ban', true)->orderBy('ma_mon_an')->firstOrFail();
        $item = app(PosOrderService::class)->addItem($order, ['dishId' => $dish->ma_mon_an, 'quantity' => 2, 'clientRequestId' => 'review-f-item']);
        app(PosOrderService::class)->sendKitchen($order, $staff, [$item->ma_chi_tiet]);
        foreach ([KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING, KitchenItemStatus::READY] as $step) {
            $item = app(KitchenService::class)->updateStatus($item->fresh(), $step, $staff);
        }
        $invoice = app(BillingService::class)->createInvoice($session->fresh());
        app(BillingService::class)->pay($invoice, ['method' => 'CASH', 'amount' => (float) $invoice->tong_thanh_toan, 'idempotencyKey' => 'review-f-cash'], $staff->ma_tai_khoan);
        $payment = $order->payment()->firstOrFail();
        $body = ['amount' => 10000, 'method' => 'CASH', 'referenceCode' => 'PC-BOIHOAN-1', 'refundReason' => 'Bồi hoàn món ra chậm'];

        // Hoàn ghi là "nhà hàng đang nợ" trong khi không nợ: bị từ chối, không đổi gì.
        $this->as('admin')->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/refund", $body)->assertUnprocessable();
        $this->assertSame(0, Refund::count());

        $this->as('admin')->postJson("/api/v1/admin/payments/{$payment->ma_thanh_toan}/refund", [...$body, 'refundType' => 'COMPENSATION'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PARTIALLY_REFUNDED')
            ->assertJsonPath('data.outstanding', fn ($value) => (float) $value === 0.0);

        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->trang_thai);
        $this->assertEquals(10000, (float) $order->fresh()->so_tien_giam_tru);
        $this->as('admin')->getJson('/api/v1/admin/payments/transactions?orderId='.$order->ma_don_hang)
            ->assertOk()->assertJsonPath('data.items.0.refunds.0.type', 'COMPENSATION');
        $closed = app(BillingService::class)->close($session->fresh(), $session->fresh()->phien_ban);
        $this->assertSame(PosSessionStatus::CLOSED, $closed->trang_thai);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'PAYMENT_REFUND_RECORDED']);
    }

    // H ---------------------------------------------------------------------------------------------

    public function test_h_a_second_manual_receipt_for_a_paid_order_is_recorded_for_review_without_sales(): void
    {
        $order = $this->qrOrder();
        $paymentId = $order->payment->ma_thanh_toan;
        $this->as('admin')->postJson("/api/v1/admin/payments/{$paymentId}/confirm", ['transactionCode' => 'MANUAL-FIRST-01', 'amount' => 150000])->assertOk();
        $before = app(ReportService::class)->dashboard([])['finance'];

        $this->as('admin')->postJson("/api/v1/admin/payments/{$paymentId}/confirm", ['transactionCode' => 'MANUAL-SECOND-02', 'amount' => 150000])->assertOk();

        $second = MoneyTransaction::where('ma_tham_chieu', 'MANUAL-SECOND-02')->sole();
        $this->assertSame(MoneyFlag::DUPLICATE, $second->canh_bao);
        $after = app(ReportService::class)->dashboard([])['finance'];
        $this->assertEquals($before['appliedToSales'], $after['appliedToSales']);
        $this->assertEquals(150000, $after['unallocatedBalance'] - $before['unallocatedBalance']);
    }

    // Nâng cấp dữ liệu cũ -----------------------------------------------------------------------------

    public function test_the_upgrade_rekeys_old_transfers_flags_collisions_for_review_and_keeps_old_refunds(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 200000, 'transactionCode' => 'FT-LEGACY-1'])->assertOk();
        $first = MoneyTransaction::where('ma_tham_chieu', 'FT-LEGACY-1')->sole();
        $this->as('admin')->postJson("/api/v1/admin/payments/transactions/{$first->ma_giao_dich_tien}/refunds", ['amount' => 10000, 'method' => 'CASH', 'referenceCode' => 'PC-LEGACY', 'refundReason' => 'Hoàn phần chuyển dư'])->assertOk();

        // Trạng thái trước bản sửa: cùng một khoản tiền được ghi hai lần dưới hai nhãn nguồn khác nhau,
        // cộng một khoản cùng mã ở tài khoản khác.
        $migration = require database_path('migrations/2026_10_21_224546_harden_payment_identity_refund_idempotency_and_compensation.php');
        $migration->down();
        DB::table('GIAO_DICH_TIEN')->where('ma_giao_dich_tien', $first->ma_giao_dich_tien)
            ->update(['nha_cung_cap' => 'VIETQR', 'tai_khoan_nhan' => '0123456789', 'du_lieu_goc' => null, 'khoa_duy_nhat' => 'VIETQR|0123456789|FT-LEGACY-1']);
        $copy = $first->fresh()->replicate();
        $copy->forceFill(['nha_cung_cap' => 'MBBANK', 'khoa_duy_nhat' => 'MBBANK|0123456789|FT-LEGACY-1', 'so_tien_da_phan_bo' => 0, 'so_tien_da_hoan' => 0, 'du_lieu_goc' => json_encode(['gateway' => 'MBBank', 'accountNumber' => '0123456789'])])->save();
        $other = $first->fresh()->replicate();
        $other->forceFill(['nha_cung_cap' => 'VIETCOMBANK', 'tai_khoan_nhan' => '9988776655', 'khoa_duy_nhat' => 'VIETCOMBANK|9988776655|FT-LEGACY-1', 'so_tien_da_phan_bo' => 0, 'so_tien_da_hoan' => 0, 'du_lieu_goc' => json_encode(['gateway' => 'Vietcombank', 'accountNumber' => '9988776655'])])->save();
        $moneyBefore = (float) DB::table('GIAO_DICH_TIEN')->sum('so_tien');

        $migration->up();

        $this->assertSame('BANK:MB|0123456789|FT-LEGACY-1', $first->fresh()->khoa_duy_nhat);
        $this->assertNotSame(MoneyFlag::DUPLICATE, $first->fresh()->canh_bao);
        $collision = $copy->fresh();
        $this->assertSame('BANK:MB|0123456789|FT-LEGACY-1#DUP'.$collision->ma_giao_dich_tien, $collision->khoa_duy_nhat);
        $this->assertSame(MoneyFlag::DUPLICATE, $collision->canh_bao);
        $this->assertSame(ReconciliationStatus::NEEDS_REVIEW, $collision->trang_thai_doi_soat);
        $this->assertSame('BANK:VCB|9988776655|FT-LEGACY-1', $other->fresh()->khoa_duy_nhat);
        $this->assertNotSame(MoneyFlag::DUPLICATE, $other->fresh()->canh_bao);
        // Không gộp hay xóa tiền tự động.
        $this->assertEquals($moneyBefore, (float) DB::table('GIAO_DICH_TIEN')->sum('so_tien'));

        $refund = Refund::sole();
        $this->assertSame('LEGACY:'.$refund->ma_hoan_tien, $refund->khoa_yeu_cau);
        $this->assertSame(Refund::TYPE_REFUND, $refund->loai_hoan);
        $this->assertEquals(0, (float) $order->fresh()->so_tien_giam_tru);
    }
}
