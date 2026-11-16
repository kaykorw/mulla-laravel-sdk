<?php

declare(strict_types=1);

namespace Mulla\Laravel\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Mulla\Laravel\Http\Controllers\WebhookController;
use Mulla\Laravel\Http\Middleware\VerifyMullaSignature;

/**
 * Registers the verified Mulla webhook endpoint. Backs the Route::mullaWebhooks() macro.
 */
final class WebhookRoutes
{
    public const NAME = 'mulla.webhook';

    private function __construct() {}

    public static function register(Router $router, ?string $path = null): Route
    {
        return $router->post($path ?? (string) config('mulla.webhook.path', 'mulla/webhook'), WebhookController::class)
            ->middleware(VerifyMullaSignature::class)
            ->name(self::NAME);
    }
}
