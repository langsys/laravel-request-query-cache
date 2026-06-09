# Laravel Request Query Cache

Per-request, in-memory deduplication for Eloquent queries. Adds two query-builder
macros — `firstCached()` and `getCached()` — that run a given query against the
database **once per request** and serve every subsequent identical query from an
in-memory store. The store is flushed automatically when the request ends, so
results never leak across requests.

This is **not** a persistent cache (no Redis/file). It only dedupes identical
queries (same SQL + same bindings) within a single request lifecycle.

## Why would I want this?

The single best use case is **a query you run to validate input that you then
need again downstream.**

Validation rules and controllers naturally re-express the same query. A rule
fetches a row to check it exists / is in the right state; then the controller
(or service) fetches that same row to actually do the work. That's two identical
round trips to the database for one logical lookup.

The usual workarounds are awkward: smuggle the already-fetched model out of the
rule into the controller, or skip the rule and re-validate inline in the
controller. With `firstCached()`/`getCached()` you don't have to. Both layers
just write the natural query — identical SQL + bindings hit the database once,
and the controller gets the row the rule already loaded.

The goal: **zero validation in the controller/service layer** — validation stays
in the rule where it belongs, and the controller reuses the query for free.

### Example: a custom rule and a controller sharing one query

A vanilla Laravel validation rule that runs a query:

```php
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PendingInvitation implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $invitation = UserInvitation::where('activation_token', $value)
            ->whereNull('redeemed_at')
            ->firstCached();

        if (! $invitation) {
            $fail('This invitation is invalid or has already been used.');
        }
    }
}
```

The controller validates, then reuses the **exact same query** — no second DB hit,
no model smuggled out of the rule, no inline re-validation:

```php
public function store(Request $request)
{
    $request->validate([
        'token' => ['required', new PendingInvitation],
    ]);

    // Identical SQL + bindings → served from the per-request cache.
    $invitation = UserInvitation::where('activation_token', $request->token)
        ->whereNull('redeemed_at')
        ->firstCached();

    $invitation->redeem($request->user());

    return response()->json($invitation);
}
```

The rule has already done the DB work; the controller's query resolves from the
in-memory store. The only requirement is that both queries are identical — same
`where`/`whereNull` clauses in the same order, so they produce the same SQL and
bindings.

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
