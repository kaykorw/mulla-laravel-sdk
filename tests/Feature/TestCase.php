<?php

declare(strict_types=1);

namespace Mulla\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mulla\Laravel\Events\EventMap;
use Mulla\Laravel\Events\WebhookReceived;
use Mulla\Laravel\Facades\Mulla;
use Mulla\Laravel\MullaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const KEY = 'sk_test_AbC123_s3cr3tRandomPart';
    protected const WEBHOOK_SECRET = 'whsec_feature_tests';

    protected function getPackageProviders($app): array
    {
        return [MullaServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Mulla' => Mulla::class];
    }

    /** Asserts no Mulla webhook event (base or typed) was dispatched; framework events are ignored. */
    protected function assertNoMullaEvents(): void
    {
        foreach ([WebhookReceived::class, ...array_values(EventMap::all())] as $class) {
            Event::assertNotDispatched($class);
        }
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mulla.secret_key', self::KEY);
        $app['config']->set('mulla.webhook.secret', self::WEBHOOK_SECRET);
        $app['config']->set('cache.default', 'array');
    }
}
