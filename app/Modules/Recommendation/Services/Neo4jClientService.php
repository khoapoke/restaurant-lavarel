<?php

namespace App\Modules\Recommendation\Services;

use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Illuminate\Support\Facades\Log;

class Neo4jClientService
{
    protected ?ClientInterface $client = null;

    /**
     * Get or initialize the Neo4j Bolt Client instance.
     *
     * @return ClientInterface
     */
    public function getClient(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $config = config('neo4j.connections.default', [
            'protocol' => env('NEO4J_PROTOCOL', 'bolt'),
            'host' => env('NEO4J_HOST', '127.0.0.1'),
            'port' => (int) env('NEO4J_PORT', 7687),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password123'),
            'database' => env('NEO4J_DATABASE', 'neo4j'),
        ]);

        $protocol = $config['protocol'] ?? 'bolt';
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 7687;
        $user = $config['username'] ?? 'neo4j';
        $pass = $config['password'] ?? 'password123';

        $uri = sprintf('%s://%s:%s@%s:%d', $protocol, urlencode($user), urlencode($pass), $host, $port);

        $this->client = ClientBuilder::create()
            ->withDriver('default', $uri)
            ->withDefaultDriver('default')
            ->build();

        return $this->client;
    }

    /**
     * Check if Neo4j server is alive and responding.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $client = $this->getClient();
            $result = $client->run('RETURN 1 AS is_alive');
            return $result->first()->get('is_alive') === 1;
        } catch (\Throwable $e) {
            Log::warning('Neo4j connection check failed: ' . $e->getMessage());
            return false;
        }
    }
}

