<?php

namespace App\Modules\Recommendation\Services;

use App\Modules\Menu\Models\Dish;
use Illuminate\Support\Facades\Log;

class DishRecommendationService
{
    protected Neo4jClientService $neo4j;

    public function __construct(Neo4jClientService $neo4j)
    {
        $this->neo4j = $neo4j;
    }

    /**
     * Get dishes frequently paired / ordered together with a given dish (Market Basket Analysis).
     *
     * @param int $dishId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getFrequentlyPairedDishes(int $dishId, int $limit = 4): array
    {
        $recommendations = [];

        try {
            $client = $this->neo4j->getClient();

            // Cypher query: Find dishes co-occurring in the same order
            $cypher = <<<'CYPHER'
MATCH (target:Dish {dishId: $dishId})<-[:INCLUDES]-(o:Order)-[:INCLUDES]->(rec:Dish)
WHERE rec.dishId <> $dishId
RETURN rec.dishId AS dishId,
       rec.name AS dishName,
       rec.price AS price,
       rec.imageUrl AS imageUrl,
       rec.categoryName AS categoryName,
       count(o) AS pairedCount
ORDER BY pairedCount DESC, rec.dishId ASC
LIMIT $limit
CYPHER;

            $result = $client->run($cypher, [
                'dishId' => (int) $dishId,
                'limit' => (int) $limit,
            ]);

            foreach ($result as $record) {
                $recommendations[] = [
                    'dishId' => (int) $record->get('dishId'),
                    'dishName' => (string) $record->get('dishName'),
                    'price' => (float) $record->get('price'),
                    'imageUrl' => (string) ($record->get('imageUrl') ?? ''),
                    'categoryName' => (string) ($record->get('categoryName') ?? ''),
                    'pairedCount' => (int) $record->get('pairedCount'),
                    'source' => 'neo4j_graph',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("Neo4j paired dishes lookup failed for dish #{$dishId}: " . $e->getMessage());
        }

        // Graceful Fallback: If Neo4j yields fewer than desired limit, supplement with related dishes from MySQL
        if (count($recommendations) < $limit) {
            $existingIds = array_merge([$dishId], array_column($recommendations, 'dishId'));
            $targetDish = Dish::find($dishId);

            $query = Dish::with('category')
                ->where('da_xoa', false)
                ->where('hien_thi', true)
                ->where('con_ban', true)
                ->whereNotIn('ma_mon_an', $existingIds);

            if ($targetDish && $targetDish->ma_danh_muc) {
                // Prioritize dishes from same category
                $query->orderByRaw('CASE WHEN ma_danh_muc = ? THEN 0 ELSE 1 END', [$targetDish->ma_danh_muc]);
            }

            $supplement = $query->limit($limit - count($recommendations))->get();

            foreach ($supplement as $item) {
                $recommendations[] = [
                    'dishId' => (int) $item->dishId,
                    'dishName' => (string) $item->dishName,
                    'price' => (float) $item->price,
                    'imageUrl' => (string) ($item->imageUrl ?? ''),
                    'categoryName' => (string) ($item->category?->categoryName ?? ''),
                    'pairedCount' => 0,
                    'source' => 'catalog_supplement',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Get personalized recommendations for a customer using Collaborative Filtering on Neo4j graph.
     *
     * @param int $accountId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getPersonalizedRecommendations(int $accountId, int $limit = 5): array
    {
        $recommendations = [];

        try {
            $client = $this->neo4j->getClient();

            // Cypher query: Collaborative filtering (users who ordered similar dishes also ordered...)
            $cypher = <<<'CYPHER'
MATCH (me:Customer {customerId: $customerId})-[:ORDERED]->(:Order)-[:INCLUDES]->(d:Dish)
MATCH (other:Customer)-[:ORDERED]->(:Order)-[:INCLUDES]->(d)
WHERE other.customerId <> me.customerId
MATCH (other)-[:ORDERED]->(:Order)-[:INCLUDES]->(rec:Dish)
WHERE NOT (me)-[:ORDERED]->(:Order)-[:INCLUDES]->(rec)
RETURN rec.dishId AS dishId,
       rec.name AS dishName,
       rec.price AS price,
       rec.imageUrl AS imageUrl,
       rec.categoryName AS categoryName,
       count(DISTINCT other) AS affinityScore
ORDER BY affinityScore DESC, rec.dishId ASC
LIMIT $limit
CYPHER;

            $result = $client->run($cypher, [
                'customerId' => (int) $accountId,
                'limit' => (int) $limit,
            ]);

            foreach ($result as $record) {
                $recommendations[] = [
                    'dishId' => (int) $record->get('dishId'),
                    'dishName' => (string) $record->get('dishName'),
                    'price' => (float) $record->get('price'),
                    'imageUrl' => (string) ($record->get('imageUrl') ?? ''),
                    'categoryName' => (string) ($record->get('categoryName') ?? ''),
                    'affinityScore' => (int) $record->get('affinityScore'),
                    'source' => 'neo4j_collaborative',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("Neo4j personalized recommendation failed for customer #{$accountId}: " . $e->getMessage());
        }

        // Graceful Fallback: If customer has no prior orders, return popular dishes
        if (count($recommendations) < $limit) {
            $existingIds = array_column($recommendations, 'dishId');
            $supplement = Dish::with('category')
                ->where('da_xoa', false)
                ->where('hien_thi', true)
                ->where('con_ban', true)
                ->whereNotIn('ma_mon_an', $existingIds)
                ->orderBy('ma_mon_an', 'asc')
                ->limit($limit - count($recommendations))
                ->get();

            foreach ($supplement as $item) {
                $recommendations[] = [
                    'dishId' => (int) $item->dishId,
                    'dishName' => (string) $item->dishName,
                    'price' => (float) $item->price,
                    'imageUrl' => (string) ($item->imageUrl ?? ''),
                    'categoryName' => (string) ($item->category?->categoryName ?? ''),
                    'affinityScore' => 0,
                    'source' => 'trending_supplement',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Record a completed order in Neo4j graph in real-time.
     *
     * @param int $orderId
     * @param int $accountId
     * @param array $items Array of ['dishId' => int, 'quantity' => int, 'price' => float]
     * @param string|null $orderDate
     * @param float $totalAmount
     * @return void
     */
    public function recordOrder(int $orderId, int $accountId, array $items, ?string $orderDate = null, float $totalAmount = 0): void
    {
        try {
            $client = $this->neo4j->getClient();

            $dateStr = $orderDate ?? now()->toIso8601String();

            $cypher = <<<'CYPHER'
MERGE (c:Customer {customerId: $customerId})
MERGE (o:Order {orderId: $orderId})
ON CREATE SET o.totalAmount = $totalAmount, o.orderDate = $orderDate
MERGE (c)-[:ORDERED]->(o)
WITH o
UNWIND $items AS item
MATCH (d:Dish {dishId: item.dishId})
MERGE (o)-[r:INCLUDES]->(d)
ON CREATE SET r.quantity = item.quantity, r.unitPrice = item.price
CYPHER;

            $client->run($cypher, [
                'customerId' => (int) $accountId,
                'orderId' => (int) $orderId,
                'totalAmount' => (float) $totalAmount,
                'orderDate' => $dateStr,
                'items' => array_map(function ($it) {
                    return [
                        'dishId' => (int) ($it['dishId'] ?? $it['ma_mon_an']),
                        'quantity' => (int) ($it['quantity'] ?? $it['so_luong'] ?? 1),
                        'price' => (float) ($it['price'] ?? $it['don_gia'] ?? 0),
                    ];
                }, $items),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to record order #{$orderId} to Neo4j: " . $e->getMessage());
        }
    }

    /**
     * Get graph statistics (Node and Relationship counts).
     *
     * @return array<string, int>
     */
    public function getGraphStats(): array
    {
        try {
            $client = $this->neo4j->getClient();

            $counts = [
                'customers' => 0,
                'dishes' => 0,
                'categories' => 0,
                'orders' => 0,
                'includesRelationships' => 0,
                'orderedRelationships' => 0,
            ];

            $res = $client->run('MATCH (c:Customer) RETURN count(c) AS cnt');
            $counts['customers'] = (int) $res->first()->get('cnt');

            $res = $client->run('MATCH (d:Dish) RETURN count(d) AS cnt');
            $counts['dishes'] = (int) $res->first()->get('cnt');

            $res = $client->run('MATCH (cat:Category) RETURN count(cat) AS cnt');
            $counts['categories'] = (int) $res->first()->get('cnt');

            $res = $client->run('MATCH (o:Order) RETURN count(o) AS cnt');
            $counts['orders'] = (int) $res->first()->get('cnt');

            $res = $client->run('MATCH ()-[r:INCLUDES]->() RETURN count(r) AS cnt');
            $counts['includesRelationships'] = (int) $res->first()->get('cnt');

            $res = $client->run('MATCH ()-[r:ORDERED]->() RETURN count(r) AS cnt');
            $counts['orderedRelationships'] = (int) $res->first()->get('cnt');

            return $counts;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}

