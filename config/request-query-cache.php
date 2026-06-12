<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotent Responses
    |--------------------------------------------------------------------------
    |
    | Configuration for the `idempotent` middleware. Unlike the per-request
    | query cache (which is in-memory and config-free), idempotency persists a
    | response in the application's cache store so that a retried request that
    | carries the same Idempotency-Key replays the original response instead of
    | executing the route a second time.
    |
    */

    'idempotency' => [

        // Master switch. When false, the middleware passes every request through.
        'enabled' => env('IDEMPOTENCY_ENABLED', true),

        // Cache store used to persist responses and acquire in-flight locks.
        // null = the application's default store. MUST support atomic locks
        // (redis, memcached, dynamodb, database, file, array).
        'store' => env('IDEMPOTENCY_CACHE_STORE'),

        // Request header inspected for the client-provided idempotency key.
        'header' => 'Idempotency-Key',

        // Seconds a stored response remains replayable. Default 24h — sized for
        // payment/order style endpoints where a late retry must not re-execute.
        'ttl' => 86400,

        // When true, a request without the header is rejected with 400. Default
        // false so the middleware is opt-in per route; flip per route via the
        // middleware parameter (e.g. 'idempotent:86400,true,user').
        'required' => false,

        // Namespacing scope for stored keys: user | ip | global | apikey.
        // 'user' falls back to the request IP when there is no authenticated user.
        // 'apikey' isolates per tenant for API-key auth (no session user); it reads
        // the request attribute named below and falls back to user/ip when absent.
        'scope' => 'user',

        // Request attribute the 'apikey' scope reads for the tenant identifier.
        // The application's auth middleware must set it, e.g.
        //   $request->attributes->set('api_key_id', $apiKey->id);
        'scope_attribute' => 'api_key_id',

        // Seconds the atomic in-flight lock is held while the route runs. A
        // second identical key arriving mid-flight gets 409 + Retry-After.
        'lock_timeout' => 10,

        // HTTP verbs the middleware acts on. Anything else passes straight through.
        'methods' => ['POST', 'PUT', 'PATCH'],

        // Header added to replayed responses so clients/observability can tell a
        // cached replay from a fresh execution. Set to null to disable.
        'replay_header' => 'Idempotency-Replayed',

    ],

];
