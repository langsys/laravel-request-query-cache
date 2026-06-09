<?php

namespace Langsys\RequestQueryCache;

use Closure;

class RequestQueryCache
{
    private array $store = [];

    public function remember(string $key, Closure $resolver): mixed
    {
        return $this->store[$key] ??= $resolver();
    }

    public function flush(): void
    {
        $this->store = [];
    }
}
