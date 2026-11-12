<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\PaymentMethod;
use App\Modules\Core\Enums\RoleType;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Dish;
use App\Modules\Reservation\Models\DiningTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ModularApiTest extends TestCase
{
    use RefreshDatabase;

    protected function createCustomerUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::CUSTOMER->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'cust_user_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'cust_' . uniqid() . '@example.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('test_token')->plainTextToken;

        return [$account, $token];
    }

    protected function createAdminUser(): array
    {
        $role = Role::firstOrCreate(['ten_vai_tro' => RoleType::ADMIN->value]);
        $account = Account::create([
            'ten_dang_nhap' => 'admin_user_' . uniqid(),
            'mat_khau' => Hash::make('password123'),
            'email' => 'admin_' . uniqid() . '@example.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_vai_tro' => $role->ma_vai_tro,
        ]);

        $token = $account->createToken('admin_token')->plainTextToken;

        return [$account, $token];
    }

    /**
     * Test public customer categories endpoint.
     */
    public function test_customer_categories_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/v1/customer/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data',
            ]);
    }

    /**
     * Test public customer dishes endpoint.
     */
    public function test_customer_dishes_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/v1/customer/dishes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data' => [
                    'items',
                    'pagination' => [
                        'currentPage',
                        'perPage',
                        'totalItems',
                        'lastPage',
                    ],
                ],
            ]);
    }

    /**
     * Test public available tables endpoint with required params.
     */
    public function test_customer_available_tables_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/v1/customer/tables/available?date=2026-12-01&time=18:00&guestCount=4');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data',
            ]);
    }

    /**
     * Test customer registration and login flow.
     */
    public function test_customer_register_and_login_flow(): void
    {
        // 1. Register
        $regData = [
            'username' => 'customer_reg_flow',
            'password' => 'secret123',
            'passwordConfirmation' => 'secret123',
            'email' => 'customer_reg_flow@example.com',
            'fullName' => 'Customer Test',
            'phoneNumber' => '0912345678',
            'birthDate' => '1995-05-15',
            'address' => '123 Nguyen Hue, Q1, HCMC',
        ];

        $regResponse = $this->postJson('/api/v1/auth/register', $regData);
        $regResponse->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data' => [
                    'accessToken',
                    'tokenType',
                    'account' => [
                        'accountId',
                        'username',
                        'email',
                        'status',
                        'role',
                    ],
                ],
            ]);

        // 2. Login
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'customer_reg_flow',
            'password' => 'secret123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data' => [
                    'accessToken',
                    'tokenType',
                    'account',
                ],
            ]);

        $token = $loginResponse->json('data.accessToken');

        // 3. Access protected profile
        $profileResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customer/profile');

        $profileResponse->assertStatus(200)
            ->assertJsonPath('data.username', 'customer_reg_flow')
            ->assertJsonPath('data.fullName', 'Customer Test');
    }

    /**
     * Test Cart workflow (add item, view cart, update quantity, remove item).
     */
    public function test_customer_cart_workflow(): void
    {
        [$customer, $token] = $this->createCustomerUser();

        $category = Category::create([
            'ten_danh_muc' => 'Khai vi',
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dish = Dish::create([
            'ten_mon_an' => 'Goi Cuon Tom Thit',
            'gia_tien' => 45000,
            'ma_danh_muc' => $category->ma_danh_muc,
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $authHeader = ['Authorization' => "Bearer {$token}"];

        // 1. Add item to cart
        $addRes = $this->withHeaders($authHeader)->postJson('/api/v1/customer/cart/items', [
            'dishId' => $dish->ma_mon_an,
            'quantity' => 2,
        ]);
        $addRes->assertStatus(200)
            ->assertJsonPath('data.totalQuantity', 2);

        // 2. View cart
        $viewRes = $this->withHeaders($authHeader)->getJson('/api/v1/customer/cart');
        $viewRes->assertStatus(200)
            ->assertJsonPath('data.totalQuantity', 2);

        // 3. Update quantity
        $updateRes = $this->withHeaders($authHeader)->putJson("/api/v1/customer/cart/items/{$dish->ma_mon_an}", [
            'quantity' => 5,
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.totalQuantity', 5);

        // 4. Remove item
        $deleteRes = $this->withHeaders($authHeader)->deleteJson("/api/v1/customer/cart/items/{$dish->ma_mon_an}");
        $deleteRes->assertStatus(200)
            ->assertJsonPath('data.totalQuantity', 0);
    }

    /**
     * Test Admin Reports & Dashboard API.
     */
    public function test_admin_reports_dashboard(): void
    {
        [$admin, $token] = $this->createAdminUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/reports/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data' => [
                    'revenue',
                    'orders',
                    'reservations',
                ],
            ]);
    }

    /**
     * Test protected endpoints return 401 unauthenticated when no token is provided.
     */
    public function test_customer_cart_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/customer/cart');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 401,
            ]);
    }

    /**
     * Test admin endpoints require admin authentication.
     */
    public function test_admin_accounts_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/admin/accounts');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 401,
            ]);
    }

    /**
     * Test DatabaseSeeder runs with pure module models.
     */
    public function test_database_seeder_populates_module_data(): void
    {
        $this->seed(\Database\Seeders\RestaurantSeeder::class);

        $this->assertDatabaseHas('TAI_KHOAN', [
            'ten_dang_nhap' => 'admin',
        ]);
        $this->assertDatabaseHas('DANH_MUC', [
            'ten_danh_muc' => 'Khai vị',
        ]);
        $this->assertDatabaseHas('BAN_AN', [
            'ten_ban' => 'Bàn T01',
        ]);
    }

    /**
     * Test Neo4j Graph Recommendations paired dishes endpoint.
     */
    public function test_graph_recommendations_paired_dishes_returns_json(): void
    {
        $category = Category::create([
            'ten_danh_muc' => 'Khai vị Test',
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dish = Dish::create([
            'ten_mon_an' => 'Nem ran Hanoi',
            'gia_tien' => 50000,
            'ma_danh_muc' => $category->ma_danh_muc,
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $response = $this->getJson("/api/v1/customer/dishes/{$dish->ma_mon_an}/recommendations/graph");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data',
            ]);
    }

    /**
     * Test Neo4j Graph statistics endpoint.
     */
    public function test_graph_stats_returns_json(): void
    {
        $response = $this->getJson('/api/v1/customer/recommendations/graph-stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data',
            ]);
    }

    /**
     * Test personalized recommendations requires customer authentication.
     */
    public function test_personalized_recommendations_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/customer/recommendations/personalized');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 401,
            ]);
    }

    /**
     * Test customer dishes search with keyword.
     */
    public function test_customer_dishes_keyword_search(): void
    {
        $category = Category::create([
            'ten_danh_muc' => 'Đồ uống',
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        Dish::create([
            'ten_mon_an' => 'Trà đào cam sả thanh mát',
            'gia_tien' => 38000,
            'ma_danh_muc' => $category->ma_danh_muc,
            'hien_thi' => true,
            'con_ban' => true,
            'da_xoa' => false,
        ]);

        $response = $this->getJson('/api/v1/customer/dishes?keyword=Trà');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'data' => [
                    'items',
                    'pagination',
                ],
            ]);

        $items = $response->json('data.items');
        $this->assertNotEmpty($items, 'Expected dishes matching keyword "Trà"');
        $this->assertStringContainsString('Trà', $items[0]['dishName']);
    }

    /**
     * Test Meilisearch scout configuration.
     */
    public function test_scout_driver_is_configured(): void
    {
        $driver = config('scout.driver');
        $this->assertTrue(in_array($driver, ['meilisearch', 'null', null], true) || empty($driver));
    }
}
