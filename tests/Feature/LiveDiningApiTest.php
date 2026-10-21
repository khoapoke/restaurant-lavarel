<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketSource;
use App\Enums\PosSessionStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\ServiceRequest;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Staff\BillingService;
use App\Services\Staff\PosSessionService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LiveDiningApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'live-dining-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
        config(['services.payment_webhook.secret' => self::WEBHOOK_SECRET]);
    }

    private function demoTable(): DiningTable
    {
        return DiningTable::where('ten_ban', config('dining.demo.table_name'))->firstOrFail();
    }

    private function otherAvailableTable(): DiningTable
    {
        return DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)
            ->where('ten_ban', '!=', config('dining.demo.table_name'))
            ->firstOrFail();
    }

    private function dineUrl(DiningTable $table, string $path = ''): string
    {
        return "/api/v1/public/dine/{$table->ma_qr}{$path}";
    }

    private function staffToken(): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => 'staff1', 'password' => '123'])
            ->assertOk()
            ->json('data.accessToken');
    }

    private function checkIn(DiningTable $table, string $name = 'Chị Mai', int $guests = 2): string
    {
        return $this->postJson($this->dineUrl($table, '/check-in'), ['guestName' => $name, 'guestCount' => $guests])
            ->assertCreated()
            ->assertJsonPath('data.live.visit.status', 'PENDING')
            ->json('data.visitToken');
    }

    /** Check in and let staff accept, returning the guest pass and staff token. */
    private function seatedGuest(DiningTable $table): array
    {
        $visitToken = $this->checkIn($table);
        $staffToken = $this->staffToken();
        $visit = TableVisit::where('token_hash', TableVisit::hashToken($visitToken))->firstOrFail();

        $this->withToken($staffToken)
            ->postJson("/api/v1/staff/live/check-ins/{$visit->ma_luot}/accept", ['guestCount' => 2])
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN');

        return [$visitToken, $staffToken];
    }

    /** @param list<array<string, mixed>> $items */
    private function submitOrder(DiningTable $table, string $visitToken, array $items, string $key): TestResponse
    {
        return $this->withHeaders(['X-Dine-Visit' => $visitToken, 'Idempotency-Key' => $key])
            ->postJson($this->dineUrl($table, '/orders'), ['items' => $items]);
    }

    private function guestState(DiningTable $table, string $visitToken): TestResponse
    {
        return $this->withHeaders(['X-Dine-Visit' => $visitToken])->getJson($this->dineUrl($table))->assertOk();
    }

    private function availableDish(): Dish
    {
        return Dish::where('con_ban', true)->where('hien_thi', true)->where('da_xoa', false)->orderBy('ma_mon_an')->firstOrFail();
    }

    public function test_unknown_or_malformed_table_tokens_are_rejected(): void
    {
        $this->getJson('/api/v1/public/dine/'.str_repeat('a', 40))
            ->assertNotFound()
            ->assertJsonPath('message', 'This table QR code is invalid or has been replaced.');

        $this->getJson('/api/v1/public/dine/short-token')->assertNotFound();
    }

    public function test_public_table_view_exposes_only_minimal_table_data(): void
    {
        $table = $this->demoTable();

        $response = $this->getJson($this->dineUrl($table))
            ->assertOk()
            ->assertJsonPath('data.table.tableName', $table->ten_ban)
            ->assertJsonPath('data.entry.hasActiveSession', false)
            ->assertJsonPath('data.visit', null)
            ->assertJsonPath('data.session', null);

        $this->assertArrayNotHasKey('tableId', $response->json('data.table'));
        $this->assertStringNotContainsString('ma_ban', $response->getContent());
    }

    public function test_guest_cannot_order_before_staff_open_the_table(): void
    {
        $table = $this->demoTable();
        $visitToken = $this->checkIn($table);

        $this->submitOrder($table, $visitToken, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]], 'round-before-open-1')
            ->assertStatus(409);

        $this->assertSame(0, KitchenTicket::where('nguon', KitchenTicketSource::TABLE_QR->value)->count());
    }

    public function test_check_in_never_opens_two_sessions_for_one_table(): void
    {
        $table = $this->demoTable();
        $visitToken = $this->checkIn($table);

        $this->postJson($this->dineUrl($table, '/check-in'), ['guestName' => 'Anh Nam', 'guestCount' => 3])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another check-in request for this table is already waiting for staff.');

        $staffToken = $this->staffToken();
        $visit = TableVisit::where('token_hash', TableVisit::hashToken($visitToken))->firstOrFail();

        $this->withToken($staffToken)->getJson('/api/v1/staff/live/queue')
            ->assertOk()
            ->assertJsonPath('data.counts.checkIns', 1)
            ->assertJsonPath('data.checkIns.0.guestName', 'Chị Mai');

        $this->withToken($staffToken)->postJson("/api/v1/staff/live/check-ins/{$visit->ma_luot}/accept")->assertOk();
        $this->withToken($staffToken)->postJson("/api/v1/staff/live/check-ins/{$visit->ma_luot}/accept")->assertStatus(409);

        $this->postJson($this->dineUrl($table, '/check-in'), ['guestName' => 'Anh Nam', 'guestCount' => 3])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This table is already being served. Join the current session instead.');

        $sessions = ServiceSession::whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))->count();
        $this->assertSame(1, $sessions);
        $this->assertSame(TableStatus::OCCUPIED, $table->fresh()->trang_thai);

        $this->guestState($table, $visitToken)
            ->assertJsonPath('data.visit.status', 'ACTIVE')
            ->assertJsonPath('data.session.canOrder', true);
    }

    public function test_staff_can_decline_a_check_in_with_a_reason(): void
    {
        $table = $this->demoTable();
        $visitToken = $this->checkIn($table);
        $visit = TableVisit::where('token_hash', TableVisit::hashToken($visitToken))->firstOrFail();
        $staffToken = $this->staffToken();

        $this->withToken($staffToken)->postJson("/api/v1/staff/live/check-ins/{$visit->ma_luot}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->withToken($staffToken)->postJson("/api/v1/staff/live/check-ins/{$visit->ma_luot}/reject", ['reason' => 'Bàn đã được giữ cho đoàn khách'])
            ->assertOk();

        $this->guestState($table, $visitToken)
            ->assertJsonPath('data.visit.status', 'REJECTED')
            ->assertJsonPath('data.visit.rejectReason', 'Bàn đã được giữ cho đoàn khách');
        $this->assertSame(TableStatus::AVAILABLE, $table->fresh()->trang_thai);
    }

    public function test_a_guest_pass_only_works_on_its_own_table(): void
    {
        $table = $this->demoTable();
        $otherTable = $this->otherAvailableTable();
        [$visitToken] = $this->seatedGuest($table);

        $this->withHeaders(['X-Dine-Visit' => $visitToken])->getJson($this->dineUrl($otherTable))
            ->assertOk()
            ->assertJsonPath('data.visit', null)
            ->assertJsonPath('data.session', null);

        $this->submitOrder($otherTable, $visitToken, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]], 'cross-table-round-1')
            ->assertUnauthorized();
    }

    public function test_submitted_round_uses_database_prices_and_reaches_the_kitchen(): void
    {
        $table = $this->demoTable();
        [$visitToken, $staffToken] = $this->seatedGuest($table);
        $dish = $this->availableDish();

        $this->submitOrder($table, $visitToken, [[
            'dishId' => $dish->ma_mon_an,
            'quantity' => 2,
            'note' => '<b>Ít cay</b>',
            'unitPrice' => 1,
            'price' => 1,
        ]], 'price-check-round-1')
            ->assertCreated()
            ->assertJsonPath('data.round.roundNumber', 1)
            ->assertJsonPath('data.round.source', 'TABLE_QR')
            ->assertJsonPath('data.live.rounds.0.items.0.unitPrice', fn ($price) => (float) $price === (float) $dish->gia_tien)
            ->assertJsonPath('data.live.rounds.0.items.0.status', 'SENT')
            ->assertJsonPath('data.live.summary.subTotal', fn ($total) => (float) $total === (float) $dish->gia_tien * 2);

        $this->assertDatabaseHas('CHI_TIET_DON_HANG', [
            'ma_mon_an' => $dish->ma_mon_an,
            'don_gia' => $dish->gia_tien,
            'ten_mon_snapshot' => $dish->ten_mon_an,
            'ghi_chu' => 'Ít cay',
            'trang_thai_bep' => KitchenItemStatus::SENT->value,
        ]);

        $this->withToken($staffToken)->getJson('/api/v1/staff/kitchen/items')
            ->assertOk()
            ->assertJsonFragment(['dishName' => $dish->ten_mon_an, 'source' => 'TABLE_QR', 'tableNames' => [$table->ten_ban]])
            ->assertJsonStructure(['data' => ['sla' => ['warningMinutes', 'lateMinutes']]]);
    }

    public function test_repeating_a_round_with_the_same_idempotency_key_does_not_duplicate_items(): void
    {
        $table = $this->demoTable();
        [$visitToken] = $this->seatedGuest($table);
        $items = [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]];

        $this->submitOrder($table, $visitToken, $items, 'double-tap-round-1')->assertCreated();
        $this->submitOrder($table, $visitToken, $items, 'double-tap-round-1')
            ->assertOk()
            ->assertJsonPath('data.created', false);
        $this->submitOrder($table, $visitToken, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 3]], 'double-tap-round-1')
            ->assertStatus(409);

        $this->assertSame(1, KitchenTicket::where('idempotency_key', 'double-tap-round-1')->count());
        $this->assertSame(1, OrderItem::where('client_request_id', 'like', 'double-tap-round-1:%')->count());
    }

    public function test_a_lost_response_retry_is_acknowledged_even_after_the_bill_is_opened(): void
    {
        $table = $this->demoTable();
        [$visitToken] = $this->seatedGuest($table);
        $items = [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 2, 'note' => 'Ít đá']];

        $this->submitOrder($table, $visitToken, $items, 'lost-response-round-1')->assertCreated();
        $session = ServiceSession::whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))->firstOrFail();
        app(BillingService::class)->createInvoice($session);

        // The device never saw the 201; after a reload it resends the stored key and payload.
        $this->submitOrder($table, $visitToken, $items, 'lost-response-round-1')
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.round.roundNumber', 1);
        // A genuinely new round is still refused while the bill is open.
        $this->submitOrder($table, $visitToken, $items, 'lost-response-round-2')->assertStatus(409);

        $this->assertSame(1, KitchenTicket::where('idempotency_key', 'lost-response-round-1')->count());
        $this->assertSame(1, OrderItem::whereHas('order', fn ($query) => $query->where('ma_phien', $session->ma_phien))->count());
    }

    public function test_round_validation_rejects_bad_quantities_unavailable_dishes_and_missing_keys(): void
    {
        $table = $this->demoTable();
        [$visitToken] = $this->seatedGuest($table);
        $dish = $this->availableDish();

        $this->submitOrder($table, $visitToken, [['dishId' => $dish->ma_mon_an, 'quantity' => 0]], 'invalid-qty-round')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
        $this->submitOrder($table, $visitToken, [['dishId' => $dish->ma_mon_an, 'quantity' => 1, 'note' => str_repeat('a', 121)]], 'long-note-round')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.note']);
        $this->flushHeaders()->withHeaders(['X-Dine-Visit' => $visitToken])
            ->postJson($this->dineUrl($table, '/orders'), ['items' => [['dishId' => $dish->ma_mon_an, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey']);

        $dish->update(['con_ban' => false]);
        $this->submitOrder($table, $visitToken, [['dishId' => $dish->ma_mon_an, 'quantity' => 1]], 'sold-out-round-1')
            ->assertStatus(409)
            ->assertJsonPath('message', "Dish '{$dish->ten_mon_an}' has just sold out");
    }

    public function test_kitchen_progress_is_reflected_in_the_guest_tracker(): void
    {
        $table = $this->demoTable();
        [$visitToken, $staffToken] = $this->seatedGuest($table);
        $this->submitOrder($table, $visitToken, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]], 'tracking-round-1')->assertCreated();
        $item = OrderItem::where('client_request_id', 'tracking-round-1:0')->firstOrFail();

        $before = $this->guestState($table, $visitToken)->json('data.version');

        foreach (['QUEUED', 'PREPARING'] as $status) {
            $this->withToken($staffToken)->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => $status])->assertOk();
        }

        $this->withHeaders(['X-Dine-Visit' => $visitToken])->getJson($this->dineUrl($table).'?since='.$before)
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.rounds.0.items.0.status', 'PREPARING');

        foreach (['READY', 'SERVED'] as $status) {
            $this->withToken($staffToken)->patchJson("/api/v1/staff/kitchen/items/{$item->ma_chi_tiet}/status", ['status' => $status])->assertOk();
        }

        $latest = $this->guestState($table, $visitToken)
            ->assertJsonPath('data.rounds.0.items.0.status', 'SERVED')
            ->assertJsonPath('data.summary.servedCount', 1)
            ->json('data.version');

        $this->withHeaders(['X-Dine-Visit' => $visitToken])->getJson($this->dineUrl($table).'?since='.$latest)
            ->assertOk()
            ->assertJsonPath('data.changed', false)
            ->assertJsonMissingPath('data.rounds');
    }

    public function test_service_requests_are_deduplicated_and_cooled_down(): void
    {
        $table = $this->demoTable();
        [$visitToken, $staffToken] = $this->seatedGuest($table);
        $headers = ['X-Dine-Visit' => $visitToken];

        $first = $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'WATER'])
            ->assertCreated()
            ->json('data.requestId');
        $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'WATER'])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.requestId', $first);

        $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'OTHER'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);
        $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'SING_A_SONG'])
            ->assertUnprocessable();

        $this->assertSame(1, ServiceRequest::where('loai', 'WATER')->count());

        $this->withToken($staffToken)->postJson("/api/v1/staff/live/service-requests/{$first}/complete")->assertOk();
        $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'WATER'])
            ->assertStatus(429);

        $this->travel(31)->seconds();
        $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'WATER'])
            ->assertCreated();
    }

    public function test_guest_can_cancel_only_a_new_request_of_their_own(): void
    {
        $table = $this->demoTable();
        [$visitToken, $staffToken] = $this->seatedGuest($table);
        $headers = ['X-Dine-Visit' => $visitToken];

        $cutlery = $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'CUTLERY'])->json('data.requestId');
        $this->withHeaders($headers)->postJson($this->dineUrl($table, "/service-requests/{$cutlery}/cancel"))
            ->assertOk()
            ->assertJsonPath('data.live.serviceRequests.0.status', 'CANCELLED');

        $call = $this->withHeaders($headers)->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'CALL_STAFF'])->json('data.requestId');
        $this->withToken($staffToken)->postJson("/api/v1/staff/live/service-requests/{$call}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'ACKNOWLEDGED');

        $this->withHeaders($headers)->postJson($this->dineUrl($table, "/service-requests/{$call}/cancel"))->assertStatus(409);
        $this->guestState($table, $visitToken)->assertJsonFragment(['type' => 'CALL_STAFF', 'status' => 'ACKNOWLEDGED']);
    }

    public function test_staff_handle_requests_from_the_live_queue(): void
    {
        $table = $this->demoTable();
        [$visitToken, $staffToken] = $this->seatedGuest($table);

        $requestId = $this->withHeaders(['X-Dine-Visit' => $visitToken])
            ->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'PAYMENT', 'preferredMethod' => 'BANK_TRANSFER_QR'])
            ->json('data.requestId');

        $this->withToken($staffToken)->getJson('/api/v1/staff/live/queue')
            ->assertOk()
            ->assertJsonPath('data.counts.newRequests', 1)
            ->assertJsonPath('data.counts.paymentRequests', 1)
            ->assertJsonPath('data.serviceRequests.0.isPriority', true)
            ->assertJsonPath('data.serviceRequests.0.preferredMethod', 'BANK_TRANSFER_QR')
            ->assertJsonPath('data.serviceRequests.0.tableName', $table->ten_ban);

        $this->withToken($staffToken)->getJson('/api/v1/staff/pos/tables')
            ->assertOk()
            ->assertJsonFragment(['paymentRequested' => true, 'newRequests' => 1, 'connectedGuests' => 1]);

        $this->withToken($staffToken)->postJson("/api/v1/staff/live/service-requests/{$requestId}/acknowledge")->assertOk();
        $this->withToken($staffToken)->postJson("/api/v1/staff/live/service-requests/{$requestId}/acknowledge")->assertStatus(409);
        $this->withToken($staffToken)->postJson("/api/v1/staff/live/service-requests/{$requestId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('YEU_CAU_PHUC_VU', ['ma_yeu_cau' => $requestId, 'trang_thai' => ServiceRequestStatus::COMPLETED->value]);
    }

    public function test_guest_pass_is_not_a_staff_or_admin_credential(): void
    {
        $table = $this->demoTable();
        $visitToken = $this->checkIn($table);

        $this->withToken($visitToken)->getJson('/api/v1/staff/live/queue')->assertUnauthorized();
        $this->withToken($visitToken)->getJson('/api/v1/staff/kitchen/items')->assertUnauthorized();
        $this->withToken($visitToken)->getJson('/api/v1/admin/tables')->assertUnauthorized();
        $this->withHeaders(['X-Dine-Visit' => $visitToken])->patchJson('/api/v1/staff/kitchen/items/1/status', ['status' => 'READY'])
            ->assertUnauthorized();
    }

    public function test_bill_flow_keeps_other_tables_private_and_ends_the_meal(): void
    {
        $table = $this->demoTable();
        [$visitToken, $staffToken] = $this->seatedGuest($table);
        $dish = $this->availableDish();
        $this->submitOrder($table, $visitToken, [['dishId' => $dish->ma_mon_an, 'quantity' => 2]], 'bill-flow-round-1')->assertCreated();

        $this->withHeaders(['X-Dine-Visit' => $visitToken])
            ->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'PAYMENT'])
            ->assertCreated();

        $session = ServiceSession::whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))->firstOrFail();
        $invoice = $this->withToken($staffToken)->postJson("/api/v1/staff/pos/sessions/{$session->ma_phien}/invoices")
            ->assertSuccessful()
            ->json('data');

        $this->assertDatabaseHas('YEU_CAU_PHUC_VU', ['ma_phien' => $session->ma_phien, 'loai' => 'PAYMENT', 'trang_thai' => 'ACKNOWLEDGED']);

        $this->guestState($table, $visitToken)
            ->assertJsonPath('data.session.status', 'AWAITING_PAYMENT')
            ->assertJsonPath('data.session.canOrder', false)
            ->assertJsonPath('data.invoice.status', 'OPEN')
            ->assertJsonPath('data.invoice.totalAmount', fn ($total) => (float) $total === (float) $dish->gia_tien * 2)
            ->assertJsonPath('data.invoice.lines.0.quantity', 2)
            ->assertJsonPath('data.invoice.qr.transferContent', "HD{$invoice['invoiceId']} THANH TOAN");

        $this->submitOrder($table, $visitToken, [['dishId' => $dish->ma_mon_an, 'quantity' => 1]], 'after-bill-round-1')->assertStatus(409);

        // Another table's guest never sees this bill.
        $otherTable = $this->otherAvailableTable();
        $otherVisit = $this->checkIn($otherTable, 'Khách bàn khác', 2);
        $this->guestState($otherTable, $otherVisit)->assertJsonPath('data.invoice', null)->assertJsonPath('data.rounds', []);
        $this->withHeaders(['X-Dine-Visit' => $otherVisit])->getJson($this->dineUrl($table))->assertJsonPath('data.invoice', null);

        // A signed bank callback for "HD{id}" settles the dine-in invoice.
        $body = json_encode(['content' => "HD{$invoice['invoiceId']} THANH TOAN", 'transferAmount' => $invoice['totalAmount'], 'transactionCode' => 'FT-LIVE-0001'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/webhooks/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertOk();

        $this->assertDatabaseHas('HOA_DON', ['ma_hoa_don' => $invoice['invoiceId'], 'trang_thai' => InvoiceStatus::PAID->value]);
        $this->assertDatabaseHas('YEU_CAU_PHUC_VU', ['ma_phien' => $session->ma_phien, 'loai' => 'PAYMENT', 'trang_thai' => 'COMPLETED']);

        OrderItem::where('client_request_id', 'bill-flow-round-1:0')->update(['trang_thai_bep' => KitchenItemStatus::READY->value]);
        $this->withToken($staffToken)->postJson("/api/v1/staff/pos/sessions/{$session->ma_phien}/close", ['expectedVersion' => $session->fresh()->phien_ban])
            ->assertOk();

        $this->guestState($table, $visitToken)
            ->assertJsonPath('data.session.status', 'CLOSED')
            ->assertJsonPath('data.session.canOrder', false)
            ->assertJsonPath('data.invoice.status', 'PAID')
            ->assertJsonPath('data.invoice.payment.method', 'BANK_TRANSFER_QR');

        $this->submitOrder($table, $visitToken, [['dishId' => $dish->ma_mon_an, 'quantity' => 1]], 'closed-session-round-1')
            ->assertStatus(409);
        $this->withHeaders(['X-Dine-Visit' => $visitToken])->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'WATER'])
            ->assertStatus(409);
    }

    /** Open a session straight from the POS (no QR check-in), as staff do for walk-ins. */
    private function openSessionAtPos(array $tableIds, string $key): ServiceSession
    {
        $staff = Account::where('ten_dang_nhap', 'staff1')->firstOrFail();

        return app(PosSessionService::class)->open($staff, [
            'tableIds' => $tableIds,
            'guestCount' => 2,
            'idempotencyKey' => $key,
        ])['session'];
    }

    private function joinCodeOf(ServiceSession $session): string
    {
        return (string) ServiceSession::findOrFail($session->ma_phien)->ma_tham_gia;
    }

    public function test_a_new_device_joins_an_open_session_with_the_table_code_but_not_after_it_ends(): void
    {
        $table = $this->demoTable();
        [$firstGuest] = $this->seatedGuest($table);

        $code = $this->guestState($table, $firstGuest)->json('data.session.joinCode');
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $code);
        $this->flushHeaders();

        $joined = $this->postJson($this->dineUrl($table, '/join'), ['guestName' => 'Bạn cùng bàn', 'joinCode' => $code])
            ->assertCreated()
            ->assertJsonPath('data.live.session.canOrder', true)
            ->json('data.visitToken');
        $this->assertSame(64, strlen($joined));

        // An approved device keeps working without re-entering the code.
        $this->withHeaders(['X-Dine-Visit' => $joined])->postJson($this->dineUrl($table, '/join'))
            ->assertOk()
            ->assertJsonPath('data.visitToken', null);

        $session = ServiceSession::whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))->firstOrFail();
        $session->update(['trang_thai' => PosSessionStatus::CANCELLED, 'dong_luc' => now()]);

        $this->flushHeaders()->postJson($this->dineUrl($table, '/join'), ['joinCode' => $code])->assertStatus(409);
        $this->submitOrder($table, $joined, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]], 'cancelled-session-round')
            ->assertStatus(409);
    }

    public function test_a_previous_guest_holding_the_fixed_qr_cannot_enter_the_next_meal(): void
    {
        $table = $this->demoTable();
        $first = $this->openSessionAtPos([$table->ma_ban], 'first-meal-session-1');
        $firstCode = $this->joinCodeOf($first);
        $oldPass = $this->postJson($this->dineUrl($table, '/join'), ['joinCode' => $firstCode])->assertCreated()->json('data.visitToken');

        app(PosSessionService::class)->cancel($first, 'Bữa trước đã kết thúc');
        $second = $this->openSessionAtPos([$table->ma_ban], 'second-meal-session-1');
        $secondCode = $this->joinCodeOf($second);
        $this->assertNotSame($first->ma_phien, $second->ma_phien);

        // Old pass, no pass, and the previous meal's code are all refused for the new meal.
        $this->withHeaders(['X-Dine-Visit' => $oldPass])->postJson($this->dineUrl($table, '/join'))->assertForbidden();
        $this->flushHeaders()->postJson($this->dineUrl($table, '/join'))->assertForbidden();
        if ($firstCode !== $secondCode) {
            $this->postJson($this->dineUrl($table, '/join'), ['joinCode' => $firstCode])->assertForbidden();
        }

        $this->guestState($table, $oldPass)
            ->assertJsonPath('data.entry.requiresJoinCode', true)
            ->assertJsonPath('data.rounds', [])
            ->assertJsonPath('data.invoice', null);
        $this->assertStringNotContainsString($secondCode, $this->flushHeaders()->getJson($this->dineUrl($table))->getContent());
        $this->submitOrder($table, $oldPass, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]], 'old-pass-round-01')
            ->assertStatus(409);
        $this->assertSame(0, TableVisit::where('ma_phien', $second->ma_phien)->count());

        // The new party, told the code by staff, joins normally.
        $this->flushHeaders()->postJson($this->dineUrl($table, '/join'), ['joinCode' => $secondCode])
            ->assertCreated()
            ->assertJsonPath('data.live.session.canOrder', true);
    }

    public function test_a_pending_check_in_cannot_slip_into_a_session_staff_opened_at_the_pos(): void
    {
        $table = $this->demoTable();
        $pendingPass = $this->checkIn($table, 'Người lạ', 2);
        $session = $this->openSessionAtPos([$table->ma_ban], 'walk-in-at-pos-1');

        $this->withHeaders(['X-Dine-Visit' => $pendingPass])->postJson($this->dineUrl($table, '/join'))->assertForbidden();
        $this->assertSame(TableVisitStatus::PENDING, TableVisit::where('token_hash', TableVisit::hashToken($pendingPass))->value('trang_thai'));

        $this->withHeaders(['X-Dine-Visit' => $pendingPass])
            ->postJson($this->dineUrl($table, '/join'), ['joinCode' => $this->joinCodeOf($session)])
            ->assertOk()
            ->assertJsonPath('data.live.session.canOrder', true);
    }

    public function test_wrong_table_codes_lock_the_session_until_staff_issue_a_new_one(): void
    {
        $table = $this->demoTable();
        $session = $this->openSessionAtPos([$table->ma_ban], 'lockout-session-1');
        $code = $this->joinCodeOf($session);
        $wrong = $code === '0000' ? '1111' : '0000';

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->postJson($this->dineUrl($table, '/join'), ['joinCode' => $wrong])->assertForbidden();
        }
        $this->postJson($this->dineUrl($table, '/join'), ['joinCode' => $wrong])->assertStatus(423);

        $this->travel(2)->minutes();
        $this->postJson($this->dineUrl($table, '/join'), ['joinCode' => $code])->assertStatus(423);
        $this->getJson($this->dineUrl($table))->assertJsonPath('data.entry.joinCodeLocked', true);

        $staffToken = $this->staffToken();
        $newCode = $this->withToken($staffToken)->postJson("/api/v1/staff/live/sessions/{$session->ma_phien}/join-code")
            ->assertOk()
            ->assertJsonPath('data.liveDining.joinCodeLocked', false)
            ->json('data.liveDining.joinCode');
        $this->withToken($staffToken)->getJson('/api/v1/staff/pos/tables')
            ->assertJsonFragment(['joinCode' => $newCode, 'joinCodeLocked' => false]);

        $this->flushHeaders()->postJson($this->dineUrl($table, '/join'), ['joinCode' => $newCode])->assertCreated();
    }

    public function test_the_table_code_is_shown_only_to_devices_inside_the_session(): void
    {
        $table = $this->demoTable();
        [$guest] = $this->seatedGuest($table);

        $this->guestState($table, $guest)->assertJsonPath('data.session.joinCode', fn ($code) => is_string($code) && strlen($code) === 4);

        $outsider = $this->flushHeaders()->getJson($this->dineUrl($table))->assertOk();
        $this->assertNull($outsider->json('data.session'));
        $this->assertTrue($outsider->json('data.entry.requiresJoinCode'));
        $this->assertArrayNotHasKey('joinCode', $outsider->json('data.entry'));
    }

    public function test_rotating_the_qr_token_revokes_the_printed_code(): void
    {
        $table = $this->demoTable();
        $oldToken = $table->ma_qr;
        $this->checkIn($table);

        $adminToken = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => '123'])->json('data.accessToken');
        $newUrl = $this->withToken($adminToken)->postJson("/api/v1/admin/tables/{$table->ma_ban}/qr-token")
            ->assertOk()
            ->json('data.qr.url');

        $this->getJson("/api/v1/public/dine/{$oldToken}")->assertNotFound();
        $this->assertStringEndsWith('/dine/'.$table->fresh()->ma_qr, $newUrl);
        $this->assertSame(0, TableVisit::where('trang_thai', TableVisitStatus::PENDING->value)->count());
    }

    public function test_check_in_attempts_are_rate_limited(): void
    {
        $table = $this->demoTable();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->postJson($this->dineUrl($table, '/check-in'), ['guestName' => 'Spam', 'guestCount' => 2]);
        }

        $this->postJson($this->dineUrl($table, '/check-in'), ['guestName' => 'Spam', 'guestCount' => 2])->assertTooManyRequests();
    }

    public function test_demo_reset_refuses_a_session_shared_with_a_regular_table_and_changes_nothing(): void
    {
        $table = $this->demoTable();
        $regular = $this->otherAvailableTable();
        $session = $this->openSessionAtPos([$table->ma_ban, $regular->ma_ban], 'shared-demo-session-1');
        $pass = $this->postJson($this->dineUrl($table, '/join'), ['joinCode' => $this->joinCodeOf($session)])->json('data.visitToken');
        $this->submitOrder($table, $pass, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 2]], 'shared-session-round-1')->assertCreated();
        $this->withHeaders(['X-Dine-Visit' => $pass])->postJson($this->dineUrl($table, '/service-requests'), ['type' => 'WATER'])->assertCreated();
        $invoice = app(BillingService::class)->createInvoice($session);
        $pendingElsewhere = $this->checkIn(DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->whereNotIn('ma_ban', [$table->ma_ban, $regular->ma_ban])->firstOrFail());

        $snapshot = fn () => [
            'session' => ServiceSession::findOrFail($session->ma_phien)->only(['trang_thai', 'dong_luc', 'ly_do_huy', 'phien_ban', 'ma_tham_gia']),
            'tables' => DiningTable::whereIn('ma_ban', [$table->ma_ban, $regular->ma_ban])->orderBy('ma_ban')->get(['ma_ban', 'trang_thai', 'ma_qr'])->toArray(),
            'items' => OrderItem::whereHas('order', fn ($query) => $query->where('ma_phien', $session->ma_phien))->orderBy('ma_chi_tiet')->get(['ma_chi_tiet', 'trang_thai_bep', 'so_luong', 'don_gia'])->toArray(),
            'invoice' => $invoice->fresh()->only(['trang_thai', 'tong_thanh_toan']),
            'requests' => ServiceRequest::where('ma_phien', $session->ma_phien)->orderBy('ma_yeu_cau')->get(['ma_yeu_cau', 'trang_thai'])->toArray(),
            'visits' => TableVisit::orderBy('ma_luot')->get(['ma_luot', 'trang_thai', 'ma_phien'])->toArray(),
        ];
        $before = $snapshot();

        $this->artisan('dining:demo', ['--rotate' => true])
            ->expectsOutputToContain("Session #{$session->ma_phien} joins {$table->ten_ban} with {$regular->ten_ban}")
            ->assertFailed();

        $this->assertSame($before, $snapshot());
        $this->assertSame(PosSessionStatus::AWAITING_PAYMENT, ServiceSession::findOrFail($session->ma_phien)->trang_thai);
        $this->assertSame(TableStatus::OCCUPIED, $regular->fresh()->trang_thai);
        $this->assertSame(TableVisitStatus::PENDING, TableVisit::where('token_hash', TableVisit::hashToken($pendingElsewhere))->value('trang_thai'));
    }

    public function test_demo_command_resets_only_the_demo_table(): void
    {
        $table = $this->demoTable();
        [$visitToken] = $this->seatedGuest($table);
        $this->submitOrder($table, $visitToken, [['dishId' => $this->availableDish()->ma_mon_an, 'quantity' => 1]], 'demo-reset-round-1')->assertCreated();
        $otherSessions = ServiceSession::whereIn('trang_thai', [PosSessionStatus::OPEN->value])->count();

        $this->artisan('dining:demo')->assertSuccessful();

        $this->assertSame(TableStatus::AVAILABLE, $table->fresh()->trang_thai);
        $this->assertSame($otherSessions - 1, ServiceSession::where('trang_thai', PosSessionStatus::OPEN->value)->count());
        $this->assertDatabaseHas('PHIEN_PHUC_VU', ['trang_thai' => PosSessionStatus::CANCELLED->value]);
        $this->assertSame(0, OrderItem::where('client_request_id', 'demo-reset-round-1:0')->where('trang_thai_bep', '!=', 'CANCELLED')->count());

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('dining:demo')->assertFailed();
    }
}
