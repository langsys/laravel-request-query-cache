<?php

namespace Langsys\RequestQueryCache\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Langsys\RequestQueryCache\RequestQueryCache;
use Langsys\RequestQueryCache\RequestQueryCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RequestQueryCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Widget::insert([
            ['name' => 'alpha'],
            ['name' => 'beta'],
        ]);

        app(RequestQueryCache::class)->flush();
    }
}
