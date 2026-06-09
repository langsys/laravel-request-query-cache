<?php

namespace Langsys\RequestQueryCache;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

class RequestQueryCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestQueryCache::class);
    }

    public function boot(): void
    {
        Builder::macro('getCached', function () {
            /** @var Builder $this */
            $cache = app(RequestQueryCache::class);
            $key = md5($this->toSql() . serialize($this->getBindings()));
            return $cache->remember($key, fn () => $this->get());
        });

        Builder::macro('firstCached', function () {
            /** @var Builder $this */
            $cache = app(RequestQueryCache::class);
            $key = md5('first:' . $this->toSql() . serialize($this->getBindings()));
            return $cache->remember($key, fn () => $this->first());
        });

        $this->app->terminating(fn () => app(RequestQueryCache::class)->flush());

        if (class_exists(RequestReceived::class)) {
            $this->app['events']->listen(
                RequestReceived::class,
                fn () => app(RequestQueryCache::class)->flush()
            );
        }
    }
}
