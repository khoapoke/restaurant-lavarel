<?php

namespace App\Modules\Recommendation\Commands;

use App\Modules\Auth\Models\Account;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Dish;
use App\Modules\Order\Models\Order;
use App\Modules\Recommendation\Services\Neo4jClientService;
use Illuminate\Console\Command;

class SyncGraphDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'neo4j:sync {--fresh : Wipe all graph nodes and constraints before importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize MySQL data (Categories, Dishes, Customers, Orders) into Neo4j Graph';

    public function handle(Neo4jClientService $neo4jService): int
    {
        $this->info('===========================================================');
        $this->info('  🚀 Synchronizing MySQL Restaurant Data into Neo4j Graph');
        $this->info('===========================================================');

        $client = $neo4jService->getClient();

        // 1. Fresh wipe if requested
        if ($this->option('fresh')) {
            $this->warn('Wiping entire graph database (--fresh)...');
            $client->run('MATCH (n) DETACH DELETE n');
            $this->info('Existing nodes and relationships deleted.');
        }

        // 2. Setup Constraints
        $this->info('Creating Schema Constraints in Neo4j...');
        try {
            $client->run('CREATE CONSTRAINT dish_id_unique IF NOT EXISTS FOR (d:Dish) REQUIRE d.dishId IS UNIQUE');
            $client->run('CREATE CONSTRAINT customer_id_unique IF NOT EXISTS FOR (c:Customer) REQUIRE c.customerId IS UNIQUE');
            $client->run('CREATE CONSTRAINT order_id_unique IF NOT EXISTS FOR (o:Order) REQUIRE o.orderId IS UNIQUE');
            $client->run('CREATE CONSTRAINT category_id_unique IF NOT EXISTS FOR (cat:Category) REQUIRE cat.categoryId IS UNIQUE');
            $this->line('  ✓ Constraints verified.');
        } catch (\Throwable $e) {
            $this->warn('  ! Constraint notice: ' . $e->getMessage());
        }

        // 3. Sync Categories
        $categories = Category::all();
        $this->info("Syncing {$categories->count()} Categories...");
        $bar = $this->output->createProgressBar($categories->count());
        foreach ($categories as $cat) {
            $client->run(
                'MERGE (cat:Category {categoryId: $id}) SET cat.name = $name',
                ['id' => (int) $cat->ma_danh_muc, 'name' => (string) $cat->ten_danh_muc]
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // 4. Sync Dishes
        $dishes = Dish::with('category')->get();
        $this->info("Syncing {$dishes->count()} Dishes...");
        $bar = $this->output->createProgressBar($dishes->count());
        foreach ($dishes as $dish) {
            $catId = $dish->ma_danh_muc ? (int) $dish->ma_danh_muc : null;
            $catName = $dish->category?->ten_danh_muc ?? '';

            $client->run(
                <<<'CYPHER'
MERGE (d:Dish {dishId: $id})
SET d.name = $name,
    d.price = $price,
    d.imageUrl = $imageUrl,
    d.categoryName = $categoryName
WITH d
MATCH (cat:Category {categoryId: $catId})
MERGE (d)-[:BELONGS_TO]->(cat)
CYPHER,
                [
                    'id' => (int) $dish->ma_mon_an,
                    'name' => (string) $dish->ten_mon_an,
                    'price' => (float) $dish->gia_tien,
                    'imageUrl' => (string) ($dish->hinh_anh ?? ''),
                    'categoryName' => $catName,
                    'catId' => $catId,
                ]
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // 5. Sync Customers
        $customers = Account::with('userProfile')->get();
        $this->info("Syncing {$customers->count()} Customers...");
        $bar = $this->output->createProgressBar($customers->count());
        foreach ($customers as $acc) {
            $client->run(
                <<<'CYPHER'
MERGE (c:Customer {customerId: $id})
SET c.username = $username,
    c.fullName = $fullName
CYPHER,
                [
                    'id' => (int) $acc->ma_tai_khoan,
                    'username' => (string) $acc->ten_dang_nhap,
                    'fullName' => (string) ($acc->userProfile?->ho_ten ?? $acc->ten_dang_nhap),
                ]
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // 6. Sync Orders and Order Items
        $orders = Order::with('dishes')->get();
        $this->info("Syncing {$orders->count()} Orders and line items...");
        $bar = $this->output->createProgressBar($orders->count());
        foreach ($orders as $order) {
            $orderDate = $order->ngay_dat_hang instanceof \DateTimeInterface
                ? $order->ngay_dat_hang->format('Y-m-d\TH:i:s')
                : (string) ($order->ngay_dat_hang ?? now()->toIso8601String());

            $itemsData = [];
            foreach ($order->dishes as $dish) {
                $itemsData[] = [
                    'dishId' => (int) $dish->ma_mon_an,
                    'quantity' => (int) ($dish->pivot->so_luong ?? 1),
                    'price' => (float) ($dish->pivot->don_gia ?? $dish->gia_tien ?? 0),
                ];
            }

            $client->run(
                <<<'CYPHER'
MATCH (c:Customer {customerId: $customerId})
MERGE (o:Order {orderId: $orderId})
SET o.totalAmount = $totalAmount,
    o.orderDate = $orderDate
MERGE (c)-[:ORDERED]->(o)
WITH o
UNWIND $items AS item
MATCH (d:Dish {dishId: item.dishId})
MERGE (o)-[r:INCLUDES]->(d)
SET r.quantity = item.quantity,
    r.unitPrice = item.price
CYPHER,
                [
                    'customerId' => (int) $order->ma_tai_khoan,
                    'orderId' => (int) $order->ma_don_hang,
                    'totalAmount' => (float) $order->tong_tien,
                    'orderDate' => $orderDate,
                    'items' => $itemsData,
                ]
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // 7. Graph Statistics Summary
        $dishCount = $client->run('MATCH (d:Dish) RETURN count(d) AS cnt')->first()->get('cnt');
        $catCount = $client->run('MATCH (cat:Category) RETURN count(cat) AS cnt')->first()->get('cnt');
        $custCount = $client->run('MATCH (c:Customer) RETURN count(c) AS cnt')->first()->get('cnt');
        $orderCount = $client->run('MATCH (o:Order) RETURN count(o) AS cnt')->first()->get('cnt');
        $relIncludes = $client->run('MATCH ()-[r:INCLUDES]->() RETURN count(r) AS cnt')->first()->get('cnt');
        $relOrdered = $client->run('MATCH ()-[r:ORDERED]->() RETURN count(r) AS cnt')->first()->get('cnt');

        $this->table(
            ['Graph Entity', 'Count in Neo4j'],
            [
                ['Categories (:Category)', $catCount],
                ['Dishes (:Dish)', $dishCount],
                ['Customers (:Customer)', $custCount],
                ['Orders (:Order)', $orderCount],
                ['Order Lines ([:INCLUDES])', $relIncludes],
                ['Customer Purchases ([:ORDERED])', $relOrdered],
            ]
        );

        $this->info('✓ Neo4j Graph Synchronization Complete! Web UI available at: http://localhost:7474');
        return Command::SUCCESS;
    }
}

