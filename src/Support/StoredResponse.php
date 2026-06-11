<?php

namespace Langsys\RequestQueryCache\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * A response frozen for replay: the request fingerprint it was produced for,
 * plus enough of the HTTP response to faithfully reconstruct it later. Stored in
 * the cache as a plain array so any serializing store (redis, file, …) can hold it.
 */
final class StoredResponse
{
    /**
     * @param  array<string, list<string|null>>  $headers
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $content,
    ) {
    }

    public static function fromResponse(string $fingerprint, Response $response): self
    {
        return new self(
            $fingerprint,
            $response->getStatusCode(),
            $response->headers->all(),
            (string) $response->getContent(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['fingerprint'],
            (int) $data['status'],
            (array) $data['headers'],
            (string) $data['content'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'status' => $this->status,
            'headers' => $this->headers,
            'content' => $this->content,
        ];
    }

    public function toResponse(): Response
    {
        $response = new Response($this->content, $this->status);

        foreach ($this->headers as $name => $values) {
            $response->headers->set($name, $values);
        }

        return $response;
    }
}
