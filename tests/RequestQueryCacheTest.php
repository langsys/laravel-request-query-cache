<?php

namespace Langsys\RequestQueryCache\Tests;

use Illuminate\Support\Facades\DB;
use Langsys\RequestQueryCache\RequestQueryCache;

class RequestQueryCacheTest extends TestCase
{
    public function testFirstCachedHitsDbOnlyOnce(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $first = Widget::query()->firstCached();
        $second = Widget::query()->firstCached();

        $cacheQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'widgets'))->values();

        $this->assertCount(1, $cacheQueries, 'firstCached should hit the DB exactly once for identical queries');
        $this->assertEquals($first->id, $second->id, 'Both calls should return the same row');
    }

    public function testGetCachedHitsDbOnlyOnce(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $first = Widget::query()->getCached();
        $second = Widget::query()->getCached();

        $cacheQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'widgets'))->values();

        $this->assertCount(1, $cacheQueries, 'getCached should hit the DB exactly once for identical queries');
        $this->assertEquals($first->pluck('id'), $second->pluck('id'), 'Both calls should return the same rows');
    }

    public function testDifferentQueriesAreNotSharedInCache(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        Widget::query()->getCached();
        Widget::query()->where('name', '!=', null)->getCached();

        $cacheQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'widgets'))->values();

        $this->assertCount(2, $cacheQueries, 'Distinct queries must each hit the DB independently');
    }

    public function testFlushClearsCache(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        Widget::query()->getCached();
        app(RequestQueryCache::class)->flush();
        Widget::query()->getCached();

        $cacheQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'widgets'))->values();

        $this->assertCount(2, $cacheQueries, 'After flush, the same query should hit the DB again');
    }
}
