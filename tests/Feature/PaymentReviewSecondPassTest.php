<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\MoneyFlag;
use App\Enums\MoneySource;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Services\Admin\ReportService;
use App\Services\Payments\PaymentLedgerService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Nghiệm thu review lần 2: (1) định danh chuyển khoản không suy ra ngân hàng từ số tài khoản đơn độc;
 * (2) hoàn một giao dịch đã áp cho nhiều đơn ghi đúng phần hoàn của từng đơn.
 */
class PaymentReviewSecondPassTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'second-pass-regression-secret';

    private const MIGRATION = '2026_10_23_090000_correct_transfer_identity_and_split_multi_order_refunds.php';

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
            'ten_nguoi_nhan' => 'Khách review 2', 'so_dien_thoai_nhan' => '0901234567',
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'),
            'kenh' => OrderChannel::ONLINE, 'loai_phuc_vu' => FulfillmentType::PICKUP, 'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
        ]);
        Payment::create(['so_tien' => $total, 'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR, 'trang_thai' => PaymentStatus::UNPAID, 'ma_don_hang' => $order->ma_don_hang]);

        return $order;
    }

    private function paymentOf(Order $order): Payment
    {
        return Payment::where('ma_don_hang', $order->ma_don_hang)->sole();
    }

    // 1. Định danh chuyển khoản ---------------------------------------------------------------------

    public function test_the_same_account_digits_at_two_named_banks_are_two_receipts(): void
    {
        $first = $this->qrOrder();
        $second = $this->qrOrder();
        $this->webhook(['orderId' => $first->ma_don_hang, 'amount' => 150000, 'gateway' => 'MBBank', 'accountNumber' => '0123456789', 'transactionCode' => 'BANK-REF-SAME'])
            ->assertOk()->assertJsonPath('data.duplicateCallback', false);
        $this->webhook(['orderId' => $second->ma_don_hang, 'amount' => 150000, 'gateway' => 'Vietcombank', 'accountNumber' => '0123456789', 'transactionCode' => 'BANK-REF-SAME'])
            ->assertOk()->assertJsonPath('data.duplicateCallback', false);
        // Ngân hàng gửi lại đúng khoản Vietcombank: vẫn là gửi lại, không thêm tiền.
        $this->webhook(['orderId' => $second->ma_don_hang, 'amount' => 150000, 'gateway' => 'VCB', 'accountNumber' => '0123456789', 'transactionCode' => 'bank-ref-same'])
            ->assertOk()->assertJsonPath('data.duplicateCallback', true);

        $rows = MoneyTransaction::where('ma_tham_chieu', 'like', 'BANK-REF-SAME')->orderBy('ma_giao_dich_tien')->get();
        $this->assertSame(['BANK:MB|0123456789|BANK-REF-SAME', 'BANK:VCB|0123456789|BANK-REF-SAME'], $rows->pluck('khoa_duy_nhat')->all());
        $this->assertSame(PaymentStatus::PAID, $this->paymentOf($first)->trang_thai);
        $this->assertSame(PaymentStatus::PAID, $this->paymentOf($second)->trang_thai);
        // Dãy số trùng tài khoản cấu hình nhưng khác ngân hàng: ghi riêng, tiền vẫn áp, chuyển đối soát.
        $this->assertSame(ReconciliationStatus::NEEDS_REVIEW, $rows[1]->trang_thai_doi_soat);
        $this->assertStringContainsString('VCB', (string) $rows[1]->ghi_chu_doi_soat);
        $this->assertSame(ReconciliationStatus::MATCHED, $rows[0]->trang_thai_doi_soat);
    }

    public function test_manual_pos_and_webhook_records_of_one_transfer_still_merge(): void
    {
        $order = $this->qrOrder();
        $this->as('admin')->postJson("/api/v1/admin/payments/{$this->paymentOf($order)->ma_thanh_toan}/confirm", ['transactionCode' => 'FT26MERGE01', 'amount' => 150000])->assertOk();

        foreach ([
            ['gateway' => 'MBBank', 'accountNumber' => '0123456789'],
            ['gateway' => 'MB'],
            ['gateway' => '970422', 'accountNumber' => '0123-456-789'],
            ['gateway' => 'VietQR', 'accountNumber' => '0123456789'],
            [],
        ] as $source) {
            $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'transactionCode' => 'ft26merge01', ...$source])
                ->assertOk()->assertJsonPath('data.duplicateCallback', true);
        }

        $transaction = MoneyTransaction::whereRaw('UPPER(ma_tham_chieu) = ?', ['FT26MERGE01'])->sole();
        $this->assertSame('BANK:MB|0123456789|FT26MERGE01', $transaction->khoa_duy_nhat);
        $this->assertEquals(150000, (float) $this->paymentOf($order)->so_tien_da_thu);
    }

    public function test_an_incomplete_identity_matching_another_bank_is_kept_and_sent_to_review(): void
    {
        $first = $this->qrOrder();
        $second = $this->qrOrder();
        // Webhook nêu ngân hàng khác nhưng không có số tài khoản.
        $this->webhook(['orderId' => $first->ma_don_hang, 'amount' => 150000, 'gateway' => 'Vietcombank', 'transactionCode' => 'FT26AMBIG01'])->assertOk();
        // Quản lý nhập tay cùng mã (không có ngân hàng): không gộp đoán, không bỏ tiền.
        $this->as('admin')->postJson("/api/v1/admin/payments/{$this->paymentOf($second)->ma_thanh_toan}/confirm", ['transactionCode' => 'FT26AMBIG01', 'amount' => 150000])->assertOk();

        $rows = MoneyTransaction::where('ma_tham_chieu', 'FT26AMBIG01')->orderBy('ma_giao_dich_tien')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['BANK:VCB||FT26AMBIG01', 'BANK:MB|0123456789|FT26AMBIG01'], $rows->pluck('khoa_duy_nhat')->all());
        $this->assertSame(ReconciliationStatus::NEEDS_REVIEW, $rows[1]->trang_thai_doi_soat);
        $this->assertStringContainsString('#'.$rows[0]->ma_giao_dich_tien, (string) $rows[1]->ghi_chu_doi_soat);
        $this->assertEquals(150000, (float) $this->paymentOf($second)->so_tien_da_thu);
    }

    public function test_the_correction_migration_separates_banks_merged_by_the_earlier_normalization(): void
    {
        $order = $this->qrOrder();
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 150000, 'gateway' => 'MBBank', 'accountNumber' => '0123456789', 'transactionCode' => 'FT-OLD-SPLIT'])->assertOk();
        $mb = MoneyTransaction::where('ma_tham_chieu', 'FT-OLD-SPLIT')->sole();
        // Trạng thái do lần chuẩn hóa 2026-10-21 để lại: dòng Vietcombank (cùng dãy số) bị gắn trùng nhầm,
        // cộng một cặp trùng thật (cùng ngân hàng, cùng tài khoản, cùng mã).
        $wrong = $mb->replicate();
        $wrong->forceFill([
            'nha_cung_cap' => 'VIETCOMBANK', 'so_tien_da_phan_bo' => 0, 'canh_bao' => MoneyFlag::DUPLICATE, 'trang_thai_doi_soat' => ReconciliationStatus::NEEDS_REVIEW,
            'du_lieu_goc' => json_encode(['gateway' => 'Vietcombank', 'accountNumber' => '0123456789']), 'khoa_duy_nhat' => 'TMP-WRONG',
            'ghi_chu_doi_soat' => 'Trùng định danh chuyển khoản với giao dịch #'.$mb->ma_giao_dich_tien.' sau khi chuẩn hóa ngân hàng/tài khoản nhận — kiểm tra có phải ghi trùng một khoản tiền.',
        ])->save();
        $wrong->forceFill(['khoa_duy_nhat' => 'BANK:MB|0123456789|FT-OLD-SPLIT#DUP'.$wrong->ma_giao_dich_tien])->save();
        $real = $mb->replicate();
        $real->forceFill(['so_tien_da_phan_bo' => 0, 'canh_bao' => MoneyFlag::DUPLICATE, 'trang_thai_doi_soat' => ReconciliationStatus::NEEDS_REVIEW, 'khoa_duy_nhat' => 'TMP-REAL'])->save();
        $real->forceFill(['khoa_duy_nhat' => 'BANK:MB|0123456789|FT-OLD-SPLIT#DUP'.$real->ma_giao_dich_tien])->save();
        $moneyBefore = (float) DB::table('GIAO_DICH_TIEN')->sum('so_tien');

        (require database_path('migrations/'.self::MIGRATION))->up();

        $this->assertSame('BANK:MB|0123456789|FT-OLD-SPLIT', $mb->fresh()->khoa_duy_nhat);
        $wrong = $wrong->fresh();
        $this->assertSame('BANK:VCB|0123456789|FT-OLD-SPLIT', $wrong->khoa_duy_nhat);
        $this->assertSame(ReconciliationStatus::NEEDS_REVIEW, $wrong->trang_thai_doi_soat);
        $this->assertStringContainsString('2026-10-21', (string) $wrong->ghi_chu_doi_soat);
        $this->assertSame('BANK:MB|0123456789|FT-OLD-SPLIT#DUP'.$real->ma_giao_dich_tien, $real->fresh()->khoa_duy_nhat);
        $this->assertSame(MoneyFlag::DUPLICATE, $real->fresh()->canh_bao);
        $this->assertEquals($moneyBefore, (float) DB::table('GIAO_DICH_TIEN')->sum('so_tien'));
    }

    // 2. Hoàn giao dịch đã áp cho nhiều đơn ------------------------------------------------------------

    /** Một khoản 100.000 ₫ trả cho hai đơn 50.000 ₫, sau đó cả hai đơn bị hủy. */
    private function sharedReceiptForTwoCancelledOrders(): array
    {
        $first = $this->qrOrder(50000);
        $second = $this->qrOrder(50000);
        $this->webhook(['orderId' => $first->ma_don_hang, 'amount' => 100000, 'transactionCode' => 'SHARED-RECEIPT'])->assertOk();
        $transaction = MoneyTransaction::where('ma_tham_chieu', 'SHARED-RECEIPT')->sole();
        $this->as('admin')->postJson("/api/v1/admin/payments/transactions/{$transaction->ma_giao_dich_tien}/allocate", ['orderId' => $second->ma_don_hang, 'amount' => 50000, 'note' => 'Khách trả chung hai đơn'])->assertOk();
        foreach ([$first, $second] as $order) {
            $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", ['cancelReason' => 'Nhà hàng hết món, hủy cả hai đơn'])->assertOk();
        }

        return [$first->fresh(), $second->fresh(), $transaction->fresh()];
    }

    private function refundTransaction(MoneyTransaction $transaction, float $amount, string $key, string $voucher = 'SHARED-REFUND'): TestResponse
    {
        return $this->as('admin')->withHeader('Idempotency-Key', $key)->postJson("/api/v1/admin/payments/transactions/{$transaction->ma_giao_dich_tien}/refunds", [
            'amount' => $amount, 'method' => 'BANK_TRANSFER', 'referenceCode' => $voucher, 'refundReason' => 'Hai đơn đều bị hủy',
        ]);
    }

    public function test_refunding_a_receipt_shared_by_two_cancelled_orders_refunds_each_order_its_own_part(): void
    {
        [$first, $second, $transaction] = $this->sharedReceiptForTwoCancelledOrders();
        $ledger = app(PaymentLedgerService::class);

        $response = $this->refundTransaction($transaction, 100000, 'shared-refund-key')->assertOk()
            ->assertJsonCount(2, 'data.refunds');
        $this->assertEqualsCanonicalizing([$first->ma_don_hang, $second->ma_don_hang], $response->json('data.refunds.*.orderId'));
        $this->assertEquals([50000, 50000], $response->json('data.refunds.*.fromAllocated'));
        // Mất phản hồi, gửi lại cùng khóa: không ghi thêm.
        $this->refundTransaction($transaction, 100000, 'shared-refund-key')->assertOk();
        $this->refundTransaction($transaction, 90000, 'shared-refund-key')->assertStatus(409);

        foreach ([$first, $second] as $order) {
            $payment = $this->paymentOf($order);
            $this->assertSame(0.0, $ledger->paidAmount($order->fresh()));
            $this->assertEquals(50000, (float) $payment->so_tien_da_hoan, 'order #'.$order->ma_don_hang);
            $this->assertSame(PaymentStatus::REFUNDED, $payment->trang_thai, 'order #'.$order->ma_don_hang);
            $this->assertSame(0.0, $ledger->refundDue($order->fresh()));
        }
        $rows = Refund::where('ma_chung_tu', 'SHARED-REFUND')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['KEY:shared-refund-key'], $rows->pluck('khoa_yeu_cau')->unique()->values()->all());
        $this->assertEquals([50000.0, 50000.0], $rows->sortBy('ma_don_hang')->map(fn (Refund $row) => (float) $row->so_tien)->values()->all());
        // Mỗi dòng hoàn gắn đúng dòng đảo phân bổ của đơn đó.
        foreach ($rows as $row) {
            $this->assertEquals(-50000, (float) PaymentAllocation::where('ma_hoan_tien', $row->ma_hoan_tien)->where('ma_don_hang', $row->ma_don_hang)->sum('so_tien'));
        }
        $fresh = $transaction->fresh();
        $this->assertEquals(100000, (float) $fresh->so_tien_da_hoan);
        $this->assertSame(0.0, $fresh->unallocatedAmount());
        $this->assertEquals(100000, app(ReportService::class)->dashboard([])['finance']['refunded']);
    }

    public function test_partial_refunds_of_a_shared_receipt_track_each_order_until_fully_refunded(): void
    {
        [$first, $second, $transaction] = $this->sharedReceiptForTwoCancelledOrders();

        $this->refundTransaction($transaction, 60000, 'partial-1', 'SHARED-REFUND-1')->assertOk();
        $byOrder = Refund::query()->get()->groupBy('ma_don_hang')->map(fn ($rows) => (float) $rows->sum('so_tien'));
        $this->assertEquals(60000, $byOrder->sum());
        $this->assertEquals([$first->ma_don_hang => 10000.0, $second->ma_don_hang => 50000.0], $byOrder->sortKeys()->all());
        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED, $this->paymentOf($first)->trang_thai);
        $this->assertEquals(40000, (float) $this->paymentOf($first)->so_tien_da_thu);
        $this->assertSame(PaymentStatus::REFUNDED, $this->paymentOf($second)->trang_thai);

        // Hoàn tiếp theo đơn thứ nhất (màn Thanh toán): chỉ rút từ đơn đó.
        $this->as('admin')->withHeader('Idempotency-Key', 'order-1-rest')->postJson("/api/v1/admin/payments/{$this->paymentOf($first)->ma_thanh_toan}/refund", [
            'amount' => 40000, 'method' => 'CASH', 'referenceCode' => 'PC-SHARED-2', 'refundReason' => 'Hoàn nốt đơn thứ nhất',
        ])->assertOk();

        $this->assertSame(PaymentStatus::REFUNDED, $this->paymentOf($first)->trang_thai);
        $this->assertEquals(50000, (float) $this->paymentOf($first)->so_tien_da_hoan);
        $this->assertEquals(50000, (float) $this->paymentOf($second)->so_tien_da_hoan);
        $this->assertEquals(100000, (float) Refund::sum('so_tien'));
        $this->assertEquals(100000, (float) $transaction->fresh()->so_tien_da_hoan);
    }

    public function test_a_refund_taking_surplus_and_order_money_records_both_parts_on_the_paying_order(): void
    {
        $order = $this->qrOrder(150000);
        $this->webhook(['orderId' => $order->ma_don_hang, 'amount' => 200000, 'transactionCode' => 'FT-SURPLUS-CANCEL'])->assertOk();
        $this->as('admin')->postJson("/api/v1/admin/orders/{$order->ma_don_hang}/cancel", ['cancelReason' => 'Khách đổi ý trước khi làm'])->assertOk();
        $transaction = MoneyTransaction::where('ma_tham_chieu', 'FT-SURPLUS-CANCEL')->sole();

        $this->refundTransaction($transaction, 200000, 'surplus-cancel', 'PC-SURPLUS')->assertOk();

        $rows = Refund::orderBy('so_tien_tu_phan_bo')->get();
        $this->assertEquals([[50000.0, 0.0], [150000.0, 150000.0]], $rows->map(fn (Refund $row) => [(float) $row->so_tien, (float) $row->so_tien_tu_phan_bo])->all());
        $this->assertSame([$order->ma_don_hang], $rows->pluck('ma_don_hang')->unique()->values()->all());
        $this->assertSame(PaymentStatus::REFUNDED, $this->paymentOf($order)->trang_thai);
        $this->assertEquals(200000, (float) $this->paymentOf($order)->so_tien_da_hoan);
    }

    public function test_the_correction_migration_splits_an_old_refund_row_across_its_orders(): void
    {
        [$first, $second, $transaction] = $this->sharedReceiptForTwoCancelledOrders();
        $this->refundTransaction($transaction, 100000, 'old-shape')->assertOk();
        // Dựng lại hình dạng dữ liệu cũ: một dòng 100.000 ₫ gắn đơn thứ hai, mọi dòng đảo trỏ về nó.
        $rows = Refund::orderBy('ma_hoan_tien')->get();
        $keep = $rows->firstWhere('ma_don_hang', $second->ma_don_hang);
        PaymentAllocation::whereIn('ma_hoan_tien', $rows->pluck('ma_hoan_tien'))->update(['ma_hoan_tien' => $keep->ma_hoan_tien]);
        Refund::whereKeyNot($keep->ma_hoan_tien)->delete();
        $keep->forceFill(['so_tien' => 100000, 'so_tien_tu_phan_bo' => 100000])->save();
        foreach ([$first, $second] as $order) {
            app(PaymentLedgerService::class)->syncOrderPayment($order->fresh());
        }
        $this->assertSame(PaymentStatus::UNPAID, $this->paymentOf($first)->trang_thai);
        $this->assertEquals(100000, (float) $this->paymentOf($second)->so_tien_da_hoan);

        (require database_path('migrations/'.self::MIGRATION))->up();

        foreach ([$first, $second] as $order) {
            $this->assertSame(PaymentStatus::REFUNDED, $this->paymentOf($order)->trang_thai);
            $this->assertEquals(50000, (float) $this->paymentOf($order)->so_tien_da_hoan);
            $row = Refund::where('ma_don_hang', $order->ma_don_hang)->sole();
            $this->assertSame('KEY:old-shape', $row->khoa_yeu_cau);
            $this->assertEquals(-50000, (float) PaymentAllocation::where('ma_hoan_tien', $row->ma_hoan_tien)->sum('so_tien'));
        }
        $this->assertEquals(100000, (float) Refund::sum('so_tien'));
    }

    public static function legacySurplusRefunds(): array
    {
        return [
            'two orders, surplus belongs to first' => [2, 'first', 150000.0, 100000.0],
            'one allocation, surplus belongs to another order' => [1, 'other', 100000.0, 50000.0],
            'two allocations, surplus belongs to another order' => [2, 'other', 150000.0, 50000.0],
            'no target, surplus stays unattributed' => [2, 'none', 150000.0, 50000.0],
        ];
    }

    #[DataProvider('legacySurplusRefunds')]
    public function test_migration_preserves_the_owner_of_surplus_in_a_legacy_refund(int $allocationCount, string $targetKind, float $receiptAmount, float $firstRefunded): void
    {
        $this->assertTrue(DB::getDatabaseName() === ':memory:' || preg_match('/(_test|testing)(_|$)/', DB::getDatabaseName()) === 1, 'Migration regression must run only on an isolated test database.');
        $orders = collect([$this->qrOrder(50000)]);
        if ($allocationCount === 2) {
            $orders->push($this->qrOrder(50000));
        }
        $target = match ($targetKind) {
            'first' => $orders->first(),
            'other' => $this->qrOrder(50000),
            default => null,
        };
        $admin = Account::where('ten_dang_nhap', 'admin')->firstOrFail();
        $ledger = app(PaymentLedgerService::class);
        $transaction = $ledger->record([
            'source' => MoneySource::WEBHOOK, 'method' => PaymentMethod::BANK_TRANSFER_QR,
            'amount' => $receiptAmount, 'reference' => 'LEGACY-SURPLUS', 'orderId' => $target?->ma_don_hang,
        ])['transaction'];
        foreach ($orders as $order) {
            $ledger->allocateManually($transaction, $order, $admin, 50000, 'Khách trả chung');
            $order->update(['trang_thai' => OrderStatus::CANCELLED]);
        }
        $legacy = Refund::create([
            'ma_giao_dich_tien' => $transaction->ma_giao_dich_tien, 'ma_don_hang' => $orders->last()->ma_don_hang,
            'so_tien' => $receiptAmount, 'so_tien_tu_phan_bo' => $allocationCount * 50000,
            'loai_hoan' => Refund::TYPE_REFUND, 'khoa_yeu_cau' => 'KEY:legacy-surplus',
            'phuong_thuc' => 'BANK_TRANSFER', 'ma_chung_tu' => 'LEGACY-SURPLUS-REFUND',
            'ly_do' => 'Hoàn cả tiền đơn và tiền dư', 'ma_nguoi_thuc_hien' => $admin->ma_tai_khoan,
            'hoan_luc' => now(), 'ghi_nhan_luc' => now(),
        ]);
        foreach ($orders as $order) {
            PaymentAllocation::create([
                'ma_giao_dich_tien' => $transaction->ma_giao_dich_tien, 'ma_don_hang' => $order->ma_don_hang,
                'so_tien' => -50000, 'loai' => 'REFUND_REVERSAL', 'ma_hoan_tien' => $legacy->ma_hoan_tien,
                'ma_nguoi_thuc_hien' => $admin->ma_tai_khoan, 'tao_luc' => now(),
            ]);
            $ledger->syncOrderPayment($order->fresh());
        }
        $transaction->refresh()->forceFill([
            'so_tien_da_phan_bo' => 0, 'so_tien_da_hoan' => $receiptAmount,
            'ma_don_hang_muc_tieu' => $target?->ma_don_hang,
        ])->save();

        (require database_path('migrations/'.self::MIGRATION))->up();

        $surplus = Refund::findOrFail($legacy->ma_hoan_tien);
        $this->assertSame($target?->ma_don_hang, $surplus->ma_don_hang);
        $this->assertSame(50000.0, (float) $surplus->so_tien);
        $this->assertSame(0.0, (float) $surplus->so_tien_tu_phan_bo);
        $this->assertSame(Refund::TYPE_REFUND, $surplus->loai_hoan);
        $this->assertSame($firstRefunded, (float) $this->paymentOf($orders->first())->so_tien_da_hoan);
        foreach ($orders as $order) {
            $this->assertSame(PaymentStatus::REFUNDED, $this->paymentOf($order)->trang_thai);
            $allocated = Refund::where('ma_don_hang', $order->ma_don_hang)->where('so_tien_tu_phan_bo', 50000)->sole();
            $this->assertSame(-50000.0, (float) PaymentAllocation::where('ma_hoan_tien', $allocated->ma_hoan_tien)->sum('so_tien'));
        }
        if ($targetKind === 'other') {
            $this->assertSame(50000.0, (float) $this->paymentOf($target)->so_tien_da_hoan);
            $this->assertSame(PaymentStatus::REFUNDED, $this->paymentOf($target)->trang_thai);
        }
        $this->assertSame($receiptAmount, (float) Refund::where('khoa_yeu_cau', 'KEY:legacy-surplus')->sum('so_tien'));
        $this->assertSame($receiptAmount, (float) $transaction->fresh()->so_tien_da_hoan);
        $rowCount = Refund::where('khoa_yeu_cau', 'KEY:legacy-surplus')->count();

        (require database_path('migrations/'.self::MIGRATION))->up();

        $this->assertSame($rowCount, Refund::where('khoa_yeu_cau', 'KEY:legacy-surplus')->count());
        $this->assertSame($receiptAmount, (float) Refund::where('khoa_yeu_cau', 'KEY:legacy-surplus')->sum('so_tien'));
    }
}
