<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\DiningTable;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerReservationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RestaurantSeeder::class);
    }

    private function customerToken(string $username = 'kh1'): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $username === 'kh1' ? '123' : 'Password@123',
        ])->assertOk()->json('data.accessToken');
    }

    public function test_customer_can_find_a_table_and_create_a_reservation(): void
    {
        $token = $this->customerToken();
        $reservationTime = now()->addDays(5)->setTime(19, 0, 0);

        $available = $this->withToken($token)->getJson('/api/v1/customer/tables/available?'.http_build_query([
            'date' => $reservationTime->toDateString(),
            'time' => '19:00',
            'guestCount' => 2,
        ]))->assertOk()->assertJsonPath('success', true);

        $tableId = $available->json('data.items.0.tableId');
        $this->assertNotNull($tableId);

        $this->withToken($token)->postJson('/api/v1/customer/reservations', [
            'tableId' => $tableId,
            'reservationTime' => $reservationTime->format('Y-m-d H:i:s'),
            'guestCount' => 2,
            'note' => 'Bàn yên tĩnh',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.table.tableId', $tableId);

        $this->assertDatabaseHas('DAT_BAN', [
            'ma_ban' => $tableId,
            'so_luong_khach' => 2,
            'trang_thai' => ReservationStatus::PENDING->value,
        ]);
    }

    public function test_customer_cannot_create_an_overlapping_reservation(): void
    {
        $reservationTime = now()->addDays(5)->setTime(18, 30, 0);
        $table = DiningTable::query()->where('suc_chua', '>=', 2)->firstOrFail();

        $this->withToken($this->customerToken('kh1'))->postJson('/api/v1/customer/reservations', [
            'tableId' => $table->ma_ban,
            'reservationTime' => $reservationTime->format('Y-m-d H:i:s'),
            'guestCount' => 2,
        ])->assertCreated();

        $this->withToken($this->customerToken('kh2'))->postJson('/api/v1/customer/reservations', [
            'tableId' => $table->ma_ban,
            'reservationTime' => $reservationTime->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'guestCount' => 2,
        ])->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_customer_can_cancel_their_upcoming_reservation(): void
    {
        $token = $this->customerToken();
        $reservationId = $this->withToken($token)->getJson('/api/v1/customer/reservations')
            ->assertOk()
            ->json('data.items.0.reservationId');

        $this->withToken($token)
            ->postJson("/api/v1/customer/reservations/{$reservationId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('DAT_BAN', [
            'ma_dat_ban' => $reservationId,
            'trang_thai' => ReservationStatus::CANCELLED->value,
        ]);
    }
}
