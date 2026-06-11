# Laravel Request Query Cache

A request caching toolkit for Laravel with two independent features:

1. **Per-request query deduplication** — `firstCached()` / `getCached()` macros
   that run a query **once per request** and serve identical repeats from an
   in-memory store flushed when the request ends. *(Not a persistent cache.)*
2. **Idempotent HTTP responses** — an `idempotent` middleware that replays the
   stored response for a repeated `Idempotency-Key` instead of executing the
   route again. *(Persistent: uses your configured cache store.)*

The two share nothing but the package — pick either, or both. Query dedup needs
no config; idempotency is opt-in per route.

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

## Idempotent HTTP responses

State-changing endpoints (payments, orders, sign-ups) get retried — by impatient
users, flaky networks, and queue workers. The `idempotent` middleware makes those
retries safe: the client sends a unique `Idempotency-Key` header, and any repeat
of that key replays the **original** response instead of running the route twice.

```php
use App\Http\Controllers\PaymentController;

Route::post('/payments', [PaymentController::class, 'store'])
    ->middleware('idempotent');
```

```http
POST /payments
Idempotency-Key: 7f3c…              # client-generated, unique per logical operation

→ first call:  runs the controller, stores the response
→ same key:    replays the stored response  (+ Idempotency-Replayed: true)
```

### How a request is handled

1. Only `POST`/`PUT`/`PATCH` are guarded (configurable). Everything else passes through.
2. No key present → `400` if the route requires it, otherwise passes through.
3. Key seen before, **same** request → the stored response is replayed.
4. Key seen before, **different** request body → `422` (the key was reused for
   something else — a client bug you want surfaced, not silently mishandled).
5. Key currently **in flight** (a concurrent duplicate) → `409` + `Retry-After: 1`.
   An atomic lock guarantees the route body runs at most once even under a
   simultaneous double-submit.

A request's identity (its *fingerprint*) is the HTTP method + route + query string
+ body. Body field order doesn't matter — `{"a":1,"b":2}` and `{"b":2,"a":1}`
are the same request. The key is namespaced by **scope** so two users can use the
same key without colliding.

### Per-route overrides

Override `ttl`, `required`, and `scope` inline — `idempotent:{ttl},{required},{scope}`:

```php
// 24h window, header mandatory, scoped per authenticated user
Route::post('/payments', …)->middleware('idempotent:86400,true,user');

// 5-minute window, optional, global (one key space for everyone)
Route::post('/webhooks/stripe', …)->middleware('idempotent:300,false,global');
```

### Configuration

Defaults work out of the box. To customize, publish the config:

```bash
php artisan vendor:publish --tag=request-query-cache-config
```

```php
// config/request-query-cache.php → 'idempotency'
'enabled'      => true,
'store'        => null,              // null = default cache store
'header'       => 'Idempotency-Key',
'ttl'          => 86400,            // seconds a response stays replayable (24h)
'required'     => false,            // 400 when the header is missing
'scope'        => 'user',           // user | ip | global
'lock_timeout' => 10,              // seconds the in-flight lock is held
'methods'      => ['POST', 'PUT', 'PATCH'],
'replay_header'=> 'Idempotency-Replayed',  // null to disable
```

**TTL guidance:** default to 24h for money/order endpoints (a retry hours later
must not double-charge — same window Stripe uses); drop to minutes for cheap,
high-volume endpoints.

### Requirements & caveats

- **The cache store must support atomic locks** — `redis`, `memcached`,
  `dynamodb`, `database`, `file`, or `array`. Set a specific store via the
  `store` config key if your default doesn't. (If the store can't lock, the
  middleware still replays but loses the concurrent-duplicate `409` guarantee.)
- **`5xx` responses are never stored** — a transient server error must re-execute
  on retry, not replay forever. `2xx`–`4xx` responses are stored.
- **Body-sensitive by design** — reusing a key with a changed payload is a `422`,
  not a silent overwrite. That's the safety guarantee.
- Streamed/binary (non-string-body) responses are passed through unstored.

The middleware also exposes `$request->attributes->get('idempotent')` (bool) and
`'idempotency-key'` to downstream code.

> **Note:** attribute-style usage (`#[Idempotent]` on a controller method) is not
> wired up yet — use the `idempotent` middleware alias or class for now.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
