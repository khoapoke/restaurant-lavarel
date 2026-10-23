<?php

namespace Tests\Feature;

use App\Enums\TableStatus;
use App\Models\DiningTable;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosFloorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Giờ cố định trong giờ nhận gọi món/đặt bàn (config/restaurant.php).
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
    }

    private function staffToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => 'staff1',
            'password' => '123',
            'rememberMe' => false,
        ])->assertOk()->json('data.accessToken');
    }

    public function test_staff_can_view_pos_floor(): void
    {
        $response = $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/pos/tables');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(7, 'data.items')
            ->assertJsonStructure([
                'data' => [
                    'syncedAt',
                    'summary' => ['total', 'available', 'reserved', 'occupied', 'cleaning', 'maintenance'],
                    'items' => [[
                        'tableId', 'tableName', 'capacity', 'status', 'location', 'activeSession',
                    ]],
                ],
            ]);
    }

    public function test_staff_can_open_a_session_and_idempotently_retry(): void
    {
        $token = $this->staffToken();
        $table = DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->firstOrFail();
        $headers = ['Idempotency-Key' => 'pos-test-open-session-0001'];
        $payload = ['tableIds' => [$table->ma_ban], 'guestCount' => 2];

        $created = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/staff/pos/sessions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.tables.0.tableId', $table->ma_ban);

        $sessionId = $created->json('data.sessionId');

        $this->assertDatabaseHas('BAN_AN', [
            'ma_ban' => $table->ma_ban,
            'trang_thai' => TableStatus::OCCUPIED->value,
        ]);

        $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/staff/pos/sessions', $payload)
            ->assertOk()
            ->assertJsonPath('data.sessionId', $sessionId);
    }

    public function test_staff_cannot_open_an_unavailable_table(): void
    {
        $table = DiningTable::where('trang_thai', TableStatus::MAINTENANCE->value)->firstOrFail();

        $this->withToken($this->staffToken())
            ->withHeader('Idempotency-Key', 'pos-test-unavailable-0001')
            ->postJson('/api/v1/staff/pos/sessions', [
                'tableIds' => [$table->ma_ban],
                'guestCount' => 2,
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_staff_can_complete_cleaning_only_for_a_cleaning_table(): void
    {
        $token = $this->staffToken();
        $cleaningTable = DiningTable::where('trang_thai', TableStatus::AVAILABLE->value)->firstOrFail();
        $cleaningTable->update(['trang_thai' => TableStatus::CLEANING]);

        $this->withToken($token)
            ->postJson("/api/v1/staff/pos/tables/{$cleaningTable->ma_ban}/complete-cleaning")
            ->assertOk()
            ->assertJsonPath('data.status', 'AVAILABLE');

        $this->assertDatabaseHas('BAN_AN', [
            'ma_ban' => $cleaningTable->ma_ban,
            'trang_thai' => TableStatus::AVAILABLE->value,
        ]);

        $maintenanceTable = DiningTable::where('trang_thai', TableStatus::MAINTENANCE->value)->firstOrFail();
        $this->withToken($token)
            ->postJson("/api/v1/staff/pos/tables/{$maintenanceTable->ma_ban}/complete-cleaning")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }
}
