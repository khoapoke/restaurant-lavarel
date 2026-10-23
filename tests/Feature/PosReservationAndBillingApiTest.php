<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\Reservation;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PosReservationAndBillingApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);

        $this->token = $this->postJson('/api/v1/auth/login', [
            'username' => 'staff1',
            'password' => '123',
        ])->assertOk()->json('data.accessToken');
    }

    private function availableTable(): DiningTable
    {
        return DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->orderBy('ma_ban')->firstOrFail();
    }

    private function confirmedReservationToday(DiningTable $table, int $minutesFromNow = 20): Reservation
    {
        $reservation = Reservation::create([
            'thoi_gian' => now()->addMinutes($minutesFromNow),
            'so_luong_khach' => 2,
            'trang_thai' => ReservationStatus::CONFIRMED,
            'ghi_chu' => 'Khách quen',
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh2')->value('ma_tai_khoan'),
            'ma_ban' => $table->ma_ban,
        ]);
        $table->update(['trang_thai' => TableStatus::RESERVED]);

        return $reservation;
    }

    /** @param array<string, mixed> $payload */
    private function openSession(array $payload, string $key): TestResponse
    {
        return $this->withToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/staff/pos/sessions', $payload);
    }

    private function sendOneDishToKitchenAndMarkReady(int $sessionId): int
    {
        $orderId = $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/orders")
            ->assertCreated()->json('data.orderId');
        $dish = Dish::where('con_ban', true)->where('hien_thi', true)->firstOrFail();
        $itemId = $this->withToken($this->token)->postJson("/api/v1/staff/pos/orders/{$orderId}/items", [
            'dishId' => $dish->ma_mon_an,
            'quantity' => 1,
            'clientRequestId' => "billing-item-{$sessionId}-".uniqid(),
        ])->assertCreated()->json('data.itemId');
        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/orders/{$orderId}/send-kitchen", ['itemIds' => [$itemId]])
            ->assertOk();
        foreach (['QUEUED', 'PREPARING', 'READY'] as $status) {
            $this->withToken($this->token)
                ->patchJson("/api/v1/staff/kitchen/items/{$itemId}/status", ['status' => $status])
                ->assertOk();
        }

        return $orderId;
    }

    public function test_staff_seats_a_reservation_and_closing_the_session_completes_it(): void
    {
        $table = $this->availableTable();
        $reservation = $this->confirmedReservationToday($table);

        $this->withToken($this->token)->getJson('/api/v1/staff/pos/tables')
            ->assertOk()
            ->assertJsonFragment(['reservationId' => $reservation->ma_dat_ban, 'isWithinSeatingWindow' => true]);

        $sessionId = $this->openSession([
            'tableIds' => [$table->ma_ban],
            'guestCount' => 2,
            'reservationId' => $reservation->ma_dat_ban,
        ], 'seat-reservation-0001')
            ->assertCreated()
            ->assertJsonPath('data.reservation.reservationId', $reservation->ma_dat_ban)
            ->json('data.sessionId');

        $this->sendOneDishToKitchenAndMarkReady($sessionId);
        $invoice = $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")
            ->assertCreated();
        $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'seat-reservation-pay-0001')
            ->postJson("/api/v1/staff/invoices/{$invoice->json('data.invoiceId')}/payments", [
                'method' => 'CASH',
                'amount' => $invoice->json('data.totalAmount'),
            ])->assertCreated();

        $version = $this->withToken($this->token)->getJson("/api/v1/staff/pos/sessions/{$sessionId}")->json('data.version');
        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/close", ['expectedVersion' => $version])
            ->assertOk()
            ->assertJsonPath('data.reservation.status', 'COMPLETED');

        $this->assertDatabaseHas('DAT_BAN', [
            'ma_dat_ban' => $reservation->ma_dat_ban,
            'trang_thai' => ReservationStatus::COMPLETED->value,
        ]);
        $this->assertDatabaseHas('BAN_AN', ['ma_ban' => $table->ma_ban, 'trang_thai' => TableStatus::CLEANING->value]);
    }

    public function test_walk_in_cannot_take_a_table_held_for_an_upcoming_reservation(): void
    {
        // Lượt 12:40: phải trả bàn lúc 12:25 (trừ 15 phút dọn) — không đủ 30 phút cho khách vãng lai.
        $table = $this->availableTable();
        $reservation = $this->confirmedReservationToday($table, 40);

        $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'walk-in-held-table-0001')
            ->assertStatus(409)
            ->assertJsonPath('message', "{$table->ten_ban} is held for reservation #{$reservation->ma_dat_ban} at 12:40. Seat that reservation or choose another table.");

        $this->assertDatabaseHas('BAN_AN', ['ma_ban' => $table->ma_ban, 'trang_thai' => TableStatus::RESERVED->value]);
    }

    public function test_walk_in_before_a_later_reservation_is_told_when_to_leave(): void
    {
        // Lượt 13:00: khách vãng lai được ngồi nhưng phải trả bàn trước 12:45 (RSV-17).
        $table = $this->availableTable();
        $this->confirmedReservationToday($table, 60);

        $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'walk-in-leave-by-0001')
            ->assertCreated()
            ->assertJsonPath('data.expectedEndAt', now()->setTime(12, 45)->toIso8601String());
    }

    public function test_reservation_for_another_table_needs_a_relocation_reason(): void
    {
        $reservedTable = $this->availableTable();
        $reservation = $this->confirmedReservationToday($reservedTable);
        $otherTable = DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->orderBy('ma_ban')->firstOrFail();

        $payload = [
            'tableIds' => [$otherTable->ma_ban],
            'guestCount' => 2,
            'reservationId' => $reservation->ma_dat_ban,
        ];
        $this->openSession($payload, 'wrong-table-seat-0001')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The reservation belongs to a different table. Give a reason to seat the guests elsewhere.');

        $sessionId = $this->openSession([...$payload, 'relocationReason' => 'Bàn đặt bị đổ nước'], 'wrong-table-seat-0002')
            ->assertCreated()
            ->assertJsonPath('data.reservation.reservationId', $reservation->ma_dat_ban)
            ->json('data.sessionId');
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SESSION_OPENED', 'ma_doi_tuong' => $sessionId, 'ly_do' => 'Bàn đặt bị đổ nước']);
    }

    public function test_session_opened_by_mistake_can_be_cancelled_before_the_kitchen_starts(): void
    {
        $table = $this->availableTable();
        $sessionId = $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'cancel-session-0001')
            ->assertCreated()->json('data.sessionId');

        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/cancel", ['reason' => 'Mở nhầm bàn'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancelReason', 'Mở nhầm bàn');

        $this->assertDatabaseHas('BAN_AN', ['ma_ban' => $table->ma_ban, 'trang_thai' => TableStatus::AVAILABLE->value]);
    }

    public function test_session_with_items_in_the_kitchen_cannot_be_cancelled(): void
    {
        $table = $this->availableTable();
        $sessionId = $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'cancel-blocked-0001')
            ->assertCreated()->json('data.sessionId');
        $this->sendOneDishToKitchenAndMarkReady($sessionId);

        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/cancel", ['reason' => 'Khách đổi ý'])
            ->assertStatus(409);

        $this->assertDatabaseHas('PHIEN_PHUC_VU', ['ma_phien' => $sessionId, 'trang_thai' => PosSessionStatus::OPEN->value]);
    }

    public function test_reopening_an_unpaid_invoice_allows_ordering_more_then_reinvoices_the_new_total(): void
    {
        $table = $this->availableTable();
        $sessionId = $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'reopen-invoice-0001')
            ->assertCreated()->json('data.sessionId');
        $orderId = $this->sendOneDishToKitchenAndMarkReady($sessionId);

        $firstInvoice = $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")
            ->assertCreated()
            ->assertJsonPath('data.qr.transferContent', fn (string $content) => str_starts_with($content, 'HD'));

        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.invoice.status', 'VOID');

        $extraDish = Dish::where('con_ban', true)->where('hien_thi', true)->orderByDesc('ma_mon_an')->firstOrFail();
        $extraItemId = $this->withToken($this->token)->postJson("/api/v1/staff/pos/orders/{$orderId}/items", [
            'dishId' => $extraDish->ma_mon_an,
            'quantity' => 1,
            'clientRequestId' => 'reopen-extra-item-0001',
        ])->assertCreated()->json('data.itemId');
        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/orders/{$orderId}/send-kitchen", ['itemIds' => [$extraItemId]])
            ->assertOk();

        $expectedTotal = $firstInvoice->json('data.totalAmount') + (float) $extraDish->gia_tien;
        $secondInvoice = $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")
            ->assertCreated()
            ->assertJsonPath('data.invoiceId', $firstInvoice->json('data.invoiceId'))
            ->assertJsonPath('data.status', 'OPEN');
        $this->assertEqualsWithDelta($expectedTotal, $secondInvoice->json('data.totalAmount'), 0.01);
    }

    public function test_qr_payment_at_the_table_records_the_bank_reference(): void
    {
        $table = $this->availableTable();
        $sessionId = $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'qr-at-table-0001')
            ->assertCreated()->json('data.sessionId');
        $this->sendOneDishToKitchenAndMarkReady($sessionId);
        $invoice = $this->withToken($this->token)->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")->assertCreated();

        $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'qr-at-table-pay-0001')
            ->postJson("/api/v1/staff/invoices/{$invoice->json('data.invoiceId')}/payments", [
                'method' => 'BANK_TRANSFER_QR',
                'amount' => $invoice->json('data.totalAmount'),
                'transactionCode' => 'FT26102199887',
            ])->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.method', 'BANK_TRANSFER_QR')
            ->assertJsonPath('data.payment.transactionCode', 'FT26102199887')
            ->assertJsonPath('data.qr', null);

        $this->assertDatabaseHas('HOA_DON', ['ma_hoa_don' => $invoice->json('data.invoiceId'), 'trang_thai' => InvoiceStatus::PAID->value]);
        $this->assertDatabaseHas('THANH_TOAN', [
            'ma_hoa_don' => $invoice->json('data.invoiceId'),
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR->value,
        ]);
    }

    public function test_paid_invoice_cannot_be_reopened(): void
    {
        $table = $this->availableTable();
        $sessionId = $this->openSession(['tableIds' => [$table->ma_ban], 'guestCount' => 2], 'paid-reopen-0001')
            ->assertCreated()->json('data.sessionId');
        $this->sendOneDishToKitchenAndMarkReady($sessionId);
        $invoice = $this->withToken($this->token)->postJson("/api/v1/staff/pos/sessions/{$sessionId}/invoices")->assertCreated();
        $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'paid-reopen-pay-0001')
            ->postJson("/api/v1/staff/invoices/{$invoice->json('data.invoiceId')}/payments", [
                'method' => 'CASH',
                'amount' => $invoice->json('data.totalAmount'),
            ])->assertCreated();

        $this->withToken($this->token)
            ->postJson("/api/v1/staff/pos/sessions/{$sessionId}/reopen")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A paid invoice cannot be reopened.');
    }
}
