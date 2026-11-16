<?php

declare(strict_types=1);

namespace Mulla\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Mulla\Laravel\Events\ChargeSucceeded;
use Mulla\Laravel\Testing\InteractsWithMulla;

final class CustomRouteTest extends TestCase
{
    use InteractsWithMulla;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mulla.webhook.auto_register', false);
        $app['config']->set('mulla.webhook.path', 'hooks/mulla');
    }

    protected function defineRoutes($router): void
    {
        Route::prefix('payments')->group(static function (): void {
            Route::mullaWebhooks('hooks/mulla');
        });
    }

    public function testMacroRegistersTheRouteWhereYouWant(): void
    {
        Event::fake();
        config(['mulla.webhook.path' => 'payments/hooks/mulla']);

        $this->postMullaWebhook('charge.success')->assertOk();
        $this->post('/mulla/webhook')->assertNotFound();

        Event::assertDispatched(ChargeSucceeded::class);
    }
}
