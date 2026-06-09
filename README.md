# Laravel Request Query Cache

Per-request, in-memory deduplication for Eloquent queries. Adds two query-builder
macros — `firstCached()` and `getCached()` — that run a given query against the
database **once per request** and serve every subsequent identical query from an
in-memory store. The store is flushed automatically when the request ends, so
results never leak across requests.

This is **not** a persistent cache (no Redis/file). It only dedupes identical
queries (same SQL + same bindings) within a single request lifecycle.

## Installation

```bash
composer require langsys/laravel-request-query-cache
```

The service provider is auto-discovered. No configuration required.

## Usage

```php
// Eloquent collection — caches ->get()
$locales = Locale::query()->getCached();

// Single model — caches ->first()
$invitation = UserInvitation::where('activation_token', $token)
    ->where('user_id', null)
    ->firstCached();
```

If the same query (identical SQL and bindings) runs again during the same
request, it returns the stored result without touching the database.

```php
$a = User::where('id', 1)->firstCached(); // hits the DB
$b = User::where('id', 1)->firstCached(); // served from cache, no DB hit
// $a === $b
```

Different queries are cached independently — bindings are part of the cache key,
so `where('id', 1)` and `where('id', 2)` never collide.

## How it works

- A `RequestQueryCache` singleton holds an in-memory `array` keyed by
  `md5(sql + serialized bindings)`.
- `getCached()` wraps `->get()`; `firstCached()` wraps `->first()` (and prefixes
  its key with `first:` so the two never collide on the same query).
- The store is flushed on `app.terminating` (covers PHP-FPM) **and** on Octane's
  `RequestReceived` event when running under [Laravel Octane](https://laravel.com/docs/octane),
  guaranteeing every request starts with an empty store.

## Caveat: writes within the same request

Because results are memoized on SQL + bindings, if you write to a row and then
re-query it with `firstCached()`/`getCached()` in the **same request**, you get
the pre-write cached value. Use the uncached `first()`/`get()` after a write you
need to read back in-request.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
