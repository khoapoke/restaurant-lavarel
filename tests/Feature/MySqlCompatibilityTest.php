<?php

namespace Tests\Feature;

use App\Models\Account;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MySqlCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_keeps_its_role_when_auto_increment_has_already_advanced(): void
    {
        DB::table('VAI_TRO')->insert(['ma_vai_tro' => 100, 'ten_vai_tro' => 'Unused test role']);
        config(['communications.enabled' => false]);
        $this->seed(RestaurantSeeder::class);
        $admin = Account::where('ten_dang_nhap', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/accounts')->assertOk();

        $this->assertTrue($admin->isAdmin());
        $this->assertSame('ADMIN', $admin->roleCode());
        $this->assertSame('STAFF', Account::where('ten_dang_nhap', 'staff1')->firstOrFail()->roleCode());
        $this->assertSame('CUSTOMER', Account::where('ten_dang_nhap', 'kh1')->firstOrFail()->roleCode());
    }
}
