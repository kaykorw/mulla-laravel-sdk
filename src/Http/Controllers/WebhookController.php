<?php

declare(strict_types=1);

namespace Mulla\Laravel\Http\Controllers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mulla\Laravel\Events\EventMap;
use Mulla\Laravel\Events\WebhookReceived;
use Mulla\Laravel\Http\Middleware\VerifyMullaSignature;
use Mulla\Webhooks\Event;

/**
 * Receives verified Mulla webhooks and turns them into Laravel events.
 */
class WebhookController
{
    public function __construct(
        protected readonly Dispatcher $events,
        protected readonly CacheFactory $cache,
        protected readonly Config $config,
        protected readonly ExceptionHandler $exceptions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = VerifyMullaSignature::event($request);
        if ($event === null) {
            return new JsonResponse(['message' => 'Route is missing the VerifyMullaSignature middleware.'], 500);
        }

        if (!$this->claim($event)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            $this->release($event);
            $this->exceptions->report($e);

            return new JsonResponse(['status' => 'error'], 500);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    protected function dispatch(Event $event): void
    {
        $this->events->dispatch(new WebhookReceived($event));

        $typed = EventMap::typed($event);
        if ($typed !== null) {
            $this->events->dispatch($typed);
        }
    }

    protected function claim(Event $event): bool
    {
        if ($event->id === '') {
            return true;
        }

        return $this->store()->add($this->key($event), time(), (int) $this->config->get('mulla.webhook.dedupe_ttl', 86400));
    }

    protected function release(Event $event): void
    {
        if ($event->id !== '') {
            $this->store()->forget($this->key($event));
        }
    }

    protected function store(): Cache
    {
        $store = $this->config->get('mulla.webhook.cache_store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    protected function key(Event $event): string
    {
        return 'mulla:webhook:' . $event->id;
    }
}
