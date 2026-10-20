<?php

return [
    // Default timeout for webhook requests, in milliseconds. A channel can override this value.
    'webhook' => [
        'timeout_ms' => 10000,
        'connect_timeout_ms' => 3000,
        // HTTP methods accepted for webhook channels.
        'allowed_methods' => ['POST', 'PUT', 'PATCH'],
        // HTTPS ports accepted for webhook destinations.
        'allowed_ports' => [443],
        // Exact hostnames or wildcard patterns; an empty list permits any public hostname.
        'allowed_hosts' => [],
        // Optional HMAC secret used to sign the timestamp and raw JSON request body.
        'signature_secret' => env('EVENT_INTEGRATION_WEBHOOK_SECRET'),
        'signature_header' => 'X-Integration-Signature',
        'timestamp_header' => 'X-Integration-Timestamp',
    ],

    'rabbitmq' => [
        // Connection name used when a RabbitMQ channel does not select a connection.
        'default_connection' => 'default',

        // Named connection settings used by RabbitMQ channels. If a name is not listed here,
        // the package also checks Laravel queue.connections for a matching connection.
        'connections' => [],
    ],

    'compiler' => [
        // Maximum outbound message count and recursive output template depth.
        'max_messages' => 1000,
        'max_depth' => 64,
    ],

    'retry' => [
        // Exponential retry delay bounds and processing lease duration, in seconds.
        'base_delay_seconds' => 5,
        'max_delay_seconds' => 3600,
        'lease_seconds' => 120,
    ],

    'retention' => [
        // Age in days before successful or exhausted activities are eligible for pruning.
        'completed_days' => 90,
    ],
];
