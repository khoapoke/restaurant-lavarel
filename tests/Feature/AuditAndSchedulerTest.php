<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\Shared\AuditLogger;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nhật ký thao tác không lưu bí mật (AUD-01) và lệnh quét đặt bàn được lên lịch (ADR-025).
 */
class AuditAndSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entries_never_store_tokens_passwords_or_join_codes(): void
    {
        $this->seed(RestaurantSeeder::class);
        $admin = Account::where('ten_dang_nhap', 'admin')->firstOrFail();

        $entry = app(AuditLogger::class)->record($admin, 'TEST_ACTION', 'SESSION', 1, 'Kiểm tra', [
            'mat_khau' => 'secret-password',
            'token' => 'plain-token',
            'nested' => ['visitToken' => 'abc', 'joinCode' => '1234', 'guestCount' => 2],
        ], ['signature' => 'hmac', 'status' => 'OPEN']);

        $stored = AuditLog::findOrFail($entry->ma_nhat_ky);
        $serialized = json_encode([$stored->truoc, $stored->sau]);
        foreach (['secret-password', 'plain-token', '"abc"', '1234', 'hmac'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertSame(2, $stored->truoc['nested']['guestCount']);
        $this->assertSame('ADMIN', $stored->vai_tro);
    }

    public function test_the_reservation_sweep_is_scheduled_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'dining:reservations:sweep'));

        $this->assertNotNull($event, 'dining:reservations:sweep must be scheduled');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
