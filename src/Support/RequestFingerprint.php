<?php

namespace Langsys\RequestQueryCache\Support;

use Illuminate\Http\Request;

/**
 * Derives the two values idempotency hangs on:
 *
 *  - storageKey(): WHERE a response is stored — namespaced by scope so two users
 *    can reuse the same client key without colliding.
 *  - fingerprint(): WHAT the request was — method + route + query + body. A repeat
 *    of the same key with a different fingerprint is a misuse (HTTP 422).
 */
final class RequestFingerprint
{
    public function __construct(
        private readonly Request $request,
        private readonly IdempotencyOptions $options,
    ) {
    }

    public function clientKey(): ?string
    {
        $key = $this->request->header($this->options->header);

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function storageKey(string $clientKey, string $scopeId): string
    {
        return implode(':', [
            'idempotency',
            $this->options->scope->value,
            $scopeId,
            hash('xxh128', $clientKey),
        ]);
    }

    public function fingerprint(): string
    {
        $route = $this->request->route();
        $routeIdentity = $route
            ? ($route->getName() ?: $route->uri())
            : $this->request->path();

        return hash('xxh128', implode('|', [
            strtoupper($this->request->method()),
            $routeIdentity,
            (string) $this->request->getQueryString(),
            $this->bodyHash(),
            (string) $this->request->getContentTypeFormat(),
        ]));
    }

    private function bodyHash(): string
    {
        $payload = $this->request->all();

        if ($payload !== []) {
            $this->recursiveKsort($payload);

            return hash('xxh128', (string) json_encode($payload));
        }

        return hash('xxh128', (string) $this->request->getContent());
    }

    /**
     * Sort by key at every level so a reordered-but-equivalent JSON body yields
     * the same fingerprint.
     *
     * @param  array<array-key, mixed>  $array
     */
    private function recursiveKsort(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
    }
}
