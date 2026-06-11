<?php

namespace Langsys\RequestQueryCache\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IdempotencyTest extends TestCase
{
    /** Counts how many times a guarded route handler actually executes. */
    public static int $handlerCalls = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The array store keeps data in-process and supports atomic locks.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('request-query-cache.idempotency.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        // POST, not required: the primary happy-path route.
        $router->post('/idem/echo', function (Request $request) {
            self::$handlerCalls++;

            return response()->json([
                'calls' => self::$handlerCalls,
                'body' => $request->all(),
            ]);
        })->middleware('idempotent:60,false,global');

        // POST, required: header enforcement.
        $router->post('/idem/required', function () {
            self::$handlerCalls++;

            return response()->json(['ok' => true]);
        })->middleware('idempotent:60,true,global');

        // GET, required: proves the verb filter short-circuits before enforcement.
        $router->get('/idem/get', function () {
            self::$handlerCalls++;

            return response()->json(['ok' => true]);
        })->middleware('idempotent:60,true,global');

        // POST that fails: 5xx must never be cached.
        $router->post('/idem/fail', function () {
            self::$handlerCalls++;

            return response()->json(['boom' => true], 500);
        })->middleware('idempotent:60,false,global');
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$handlerCalls = 0;
    }

    public function testReplaysStoredResponseAndRunsHandlerOnce(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'abc')->postJson('/idem/echo', ['a' => 1]);
        $second = $this->withHeader('Idempotency-Key', 'abc')->postJson('/idem/echo', ['a' => 1]);

        $first->assertOk();
        $second->assertOk();

        $this->assertSame(1, self::$handlerCalls, 'Handler must run exactly once for a repeated key');
        $this->assertSame($first->json(), $second->json(), 'Replay must be byte-identical');
        $second->assertHeader('Idempotency-Replayed', 'true');
        $first->assertHeaderMissing('Idempotency-Replayed');
    }

    public function testReorderedBodyIsTreatedAsIdentical(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'order')->postJson('/idem/echo', ['a' => 1, 'b' => 2]);
        $second = $this->withHeader('Idempotency-Key', 'order')->postJson('/idem/echo', ['b' => 2, 'a' => 1]);

        $first->assertOk();
        $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, self::$handlerCalls);
    }

    public function testSameKeyDifferentBodyReturns422(): void
    {
        $this->withHeader('Idempotency-Key', 'dup')->postJson('/idem/echo', ['a' => 1])->assertOk();
        $this->withHeader('Idempotency-Key', 'dup')->postJson('/idem/echo', ['a' => 2])->assertStatus(422);

        $this->assertSame(1, self::$handlerCalls, 'The conflicting second request must not execute');
    }

    public function testMissingKeyWhenRequiredReturns400(): void
    {
        $this->postJson('/idem/required', [])->assertStatus(400);

        $this->assertSame(0, self::$handlerCalls);
    }

    public function testMissingKeyWhenNotRequiredPassesThrough(): void
    {
        $this->postJson('/idem/echo', ['a' => 1])->assertOk();
        $this->postJson('/idem/echo', ['a' => 1])->assertOk();

        $this->assertSame(2, self::$handlerCalls, 'Without a key, every request executes');
    }

    public function testGetRequestsBypassEvenWhenRequired(): void
    {
        $this->getJson('/idem/get')->assertOk();
        $this->getJson('/idem/get')->assertOk();

        $this->assertSame(2, self::$handlerCalls, 'GET is never guarded, and required must not trigger');
    }

    public function testServerErrorsAreNotCached(): void
    {
        $this->withHeader('Idempotency-Key', 'fail')->postJson('/idem/fail', ['a' => 1])->assertStatus(500);
        $this->withHeader('Idempotency-Key', 'fail')->postJson('/idem/fail', ['a' => 1])->assertStatus(500);

        $this->assertSame(2, self::$handlerCalls, 'A 5xx must re-execute on retry, not replay');
    }

    public function testConcurrentInFlightKeyReturns409(): void
    {
        $storageKey = 'idempotency:global:global:' . hash('xxh128', 'locktest');

        // Simulate another worker holding the in-flight lock for this key.
        $held = Cache::store('array')->getStore()->lock($storageKey . ':lock', 10);
        $this->assertTrue($held->get());

        $response = $this->withHeader('Idempotency-Key', 'locktest')->postJson('/idem/echo', ['a' => 1]);

        $response->assertStatus(409);
        $response->assertHeader('Retry-After', '1');
        $this->assertSame(0, self::$handlerCalls);

        $held->release();
    }
}
