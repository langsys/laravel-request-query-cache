<?php

namespace Langsys\RequestQueryCache\Support;

use Langsys\RequestQueryCache\Enums\IdempotencyScope;

/**
 * Immutable, fully-resolved options for a single idempotent request, built from
 * the package config and any per-route middleware parameters that override it.
 */
final class IdempotencyOptions
{
    /**
     * @param  list<string>  $methods
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $store,
        public readonly string $header,
        public readonly int $ttl,
        public readonly bool $required,
        public readonly IdempotencyScope $scope,
        public readonly int $lockTimeout,
        public readonly array $methods,
        public readonly ?string $replayHeader,
    ) {
    }

    /**
     * Resolve options from config, applying optional per-route overrides passed
     * as middleware parameters: 'idempotent:{ttl},{required},{scope}'.
     */
    public static function resolve(?string $ttl = null, ?string $required = null, ?string $scope = null): self
    {
        /** @var array<string, mixed> $config */
        $config = config('request-query-cache.idempotency', []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            store: ($config['store'] ?? null) ?: null,
            header: (string) ($config['header'] ?? 'Idempotency-Key'),
            ttl: $ttl !== null ? (int) $ttl : (int) ($config['ttl'] ?? 86400),
            required: $required !== null
                ? filter_var($required, FILTER_VALIDATE_BOOL)
                : (bool) ($config['required'] ?? false),
            scope: IdempotencyScope::from($scope ?? (string) ($config['scope'] ?? 'user')),
            lockTimeout: (int) ($config['lock_timeout'] ?? 10),
            methods: array_map('strtoupper', (array) ($config['methods'] ?? ['POST', 'PUT', 'PATCH'])),
            replayHeader: ($config['replay_header'] ?? null) ?: null,
        );
    }
}
