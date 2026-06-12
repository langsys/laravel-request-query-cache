<?php

namespace Langsys\RequestQueryCache\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Langsys\RequestQueryCache\Enums\IdempotencyScope;
use Langsys\RequestQueryCache\Support\IdempotencyOptions;
use Langsys\RequestQueryCache\Support\RequestFingerprint;
use Langsys\RequestQueryCache\Support\StoredResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Replays the stored response for a previously seen Idempotency-Key instead of
 * re-running the route. Safe to apply to any state-changing endpoint.
 *
 * Usage:
 *   ->middleware('idempotent')
 *   ->middleware('idempotent:86400,true,user')   // ttl, required, scope overrides
 */
class Idempotent
{
    public function handle(
        Request $request,
        Closure $next,
        ?string $ttl = null,
        ?string $required = null,
        ?string $scope = null,
    ): Response {
        $options = IdempotencyOptions::resolve($ttl, $required, $scope);

        // Disabled, or a verb we don't guard (e.g. GET) — straight through.
        if (! $options->enabled || ! in_array(strtoupper($request->method()), $options->methods, true)) {
            return $next($request);
        }

        $fingerprinter = new RequestFingerprint($request, $options);
        $clientKey = $fingerprinter->clientKey();

        if ($clientKey === null) {
            if ($options->required) {
                throw new HttpException(400, "Missing required header: {$options->header}");
            }

            return $next($request);
        }

        $store = Cache::store($options->store);
        $scopeId = $this->scopeId($request, $options);
        $storageKey = $fingerprinter->storageKey($clientKey, $scopeId);
        $fingerprint = $fingerprinter->fingerprint();

        // Fast path: a response is already on file for this key.
        if ($replay = $this->lookup($store, $storageKey, $fingerprint, $options)) {
            return $replay;
        }

        $underlying = $store->getStore();
        $lock = $underlying instanceof LockProvider
            ? $underlying->lock($storageKey . ':lock', $options->lockTimeout)
            : null;

        if ($lock && ! $lock->get()) {
            throw new HttpException(
                409,
                'A request with this idempotency key is currently being processed',
                null,
                ['Retry-After' => '1'],
            );
        }

        try {
            // Re-check inside the lock: the first holder may have just stored it.
            if ($replay = $this->lookup($store, $storageKey, $fingerprint, $options)) {
                return $replay;
            }

            $request->attributes->set('idempotent', true);
            $request->attributes->set('idempotency-key', $clientKey);

            $response = $next($request);

            // Persist final responses only — never cache a transient 5xx, and skip
            // streamed/binary bodies we can't faithfully replay.
            if ($response->getStatusCode() < 500 && $response->getContent() !== false) {
                $store->put(
                    $storageKey,
                    StoredResponse::fromResponse($fingerprint, $response)->toArray(),
                    $options->ttl,
                );
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Return a replayable response if one is stored, or throw 422 when the same
     * key was previously used with a different request.
     */
    private function lookup(
        Repository $store,
        string $storageKey,
        string $fingerprint,
        IdempotencyOptions $options,
    ): ?Response {
        $existing = $store->get($storageKey);

        if (! is_array($existing)) {
            return null;
        }

        $stored = StoredResponse::fromArray($existing);

        if ($stored->fingerprint !== $fingerprint) {
            throw new HttpException(422, 'Idempotency key already used with different request parameters');
        }

        $response = $stored->toResponse();

        if ($options->replayHeader !== null) {
            $response->headers->set($options->replayHeader, 'true');
        }

        return $response;
    }

    private function scopeId(Request $request, IdempotencyOptions $options): string
    {
        return match ($options->scope) {
            IdempotencyScope::GlobalScope => 'global',
            IdempotencyScope::Ip => 'ip:' . $request->ip(),
            IdempotencyScope::ApiKey => ($apiKeyId = $request->attributes->get($options->scopeAttribute)) !== null
                ? 'apikey:' . $apiKeyId
                : $this->userScopeId($request),
            IdempotencyScope::User => $this->userScopeId($request),
        };
    }

    /**
     * Authenticated user when present, otherwise the client IP. Also the fallback
     * for the apikey scope when the application has not populated the configured
     * scope attribute on the request.
     */
    private function userScopeId(Request $request): string
    {
        return $request->user() !== null
            ? 'user:' . $request->user()->getAuthIdentifier()
            : 'ip:' . $request->ip();
    }
}
