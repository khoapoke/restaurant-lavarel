<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Neo4j Connection Name
    |--------------------------------------------------------------------------
    */
    'default' => env('NEO4J_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Neo4j Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'default' => [
            'protocol' => env('NEO4J_PROTOCOL', 'bolt'),
            'host' => env('NEO4J_HOST', '127.0.0.1'),
            'port' => (int) env('NEO4J_PORT', 7687),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password123'),
            'database' => env('NEO4J_DATABASE', 'neo4j'),
        ],
    ],
];

