<?php

declare(strict_types=1);

namespace Mulla\Laravel\Testing;

use Illuminate\Testing\TestResponse;
use Mulla\Enums\WebhookEventType;
use Mulla\Laravel\Facades\Mulla;
use Mulla\Testing\FakeTransport;
use Mulla\Testing\WebhookSigner;

/**
 * Helpers for your application's feature tests:
 *
 *     use Mulla\Laravel\Testing\InteractsWithMulla;
 *
 *     $fake = $this->fakeMulla();
 *     $fake->pushJson([...]);
 *
 *     $this->postMullaWebhook('charge.success', ['metadata' => ['order_id' => $order->id]])
 *         ->assertOk();
 *
 * @mixin \Illuminate\Foundation\Testing\TestCase
 */
trait InteractsWithMulla
{
    /** Swap the Mulla client for an offline one; returns its transport. */
    protected function fakeMulla(): FakeTransport
    {
        return Mulla::fake();
    }

    /**
     * Sign a webhook with the configured secret (a test secret is set if none is) and post it
     * to the Mulla webhook route, exactly like Mulla would.
     *
     * @param array<string, mixed> $data merged over realistic defaults for the event type
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postMullaWebhook(WebhookEventType|string $type, array $data = [], ?string $eventId = null): TestResponse
    {
        $secret = config('mulla.webhook.secret');
        if (!is_string($secret) || $secret === '') {
            $secret = 'whsec_test_' . bin2hex(random_bytes(8));
            config(['mulla.webhook.secret' => $secret]);
        }

        $signed = WebhookSigner::event($type, WebhookPayloads::for($type, $data), $secret, $eventId);

        $server = [];
        foreach ($signed['headers'] as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $this->call('POST', '/' . ltrim((string) config('mulla.webhook.path', 'mulla/webhook'), '/'), [], [], [], $server, $signed['body']);
    }
}
