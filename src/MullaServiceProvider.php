<?php

declare(strict_types=1);

namespace Mulla\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mulla\Laravel\Console\InstallCommand;
use Mulla\Laravel\Console\WebhookSimulateCommand;
use Mulla\Laravel\Console\WebhookTestCommand;
use Mulla\Laravel\Routing\WebhookRoutes;
use Mulla\MullaClient;

/**
 * Registers the Mulla client, config, webhook route and Artisan commands.
 * Auto-discovered; nothing to add to config/app.php.
 */
final class MullaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mulla.php', 'mulla');

        $this->app->singleton(ClientFactory::class, static fn(Application $app): ClientFactory => new ClientFactory(
            $app->make('config'),
            $app->bound('log') ? $app->make('log') : null,
        ));

        // Built lazily: a missing key only fails when Mulla is actually used, never at boot.
        $this->app->singleton(MullaClient::class, static fn(Application $app): MullaClient => $app->make(ClientFactory::class)->make());
        $this->app->alias(MullaClient::class, 'mulla');
    }

    public function boot(): void
    {
        $this->registerRouteMacro();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/mulla.php' => $this->app->configPath('mulla.php')], 'mulla-config');
            $this->commands([InstallCommand::class, WebhookTestCommand::class, WebhookSimulateCommand::class]);
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app->make('config');
        if ($config->get('mulla.webhook.auto_register', true) && !$this->routesAreCached()) {
            /** @var Router $router */
            $router = $this->app->make('router');
            $router->group(
                ['middleware' => (array) $config->get('mulla.webhook.middleware', [])],
                static function (Router $router): void {
                    WebhookRoutes::register($router);
                },
            );
        }
    }

    /**
     * Route::mullaWebhooks('hooks/mulla') registers the verified webhook endpoint wherever you want it.
     */
    private function registerRouteMacro(): void
    {
        if (Router::hasMacro('mullaWebhooks')) {
            return;
        }

        Router::macro('mullaWebhooks', function (?string $path = null): Route {
            /** @var Router $this */
            return WebhookRoutes::register($this, $path);
        });
    }

    private function routesAreCached(): bool
    {
        return $this->app instanceof CachesRoutes && $this->app->routesAreCached();
    }
}
