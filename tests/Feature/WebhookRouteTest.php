<?php

declare(strict_types=1);

namespace Mulla\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Mulla\Laravel\Events\ChargeSucceeded;
use Mulla\Laravel\Events\InvoicePaid;
use Mulla\Laravel\Events\PayoutFailed;
use Mulla\Laravel\Events\WebhookReceived;
use Mulla\Laravel\Testing\InteractsWithMulla;
use Mulla\Testing\WebhookSigner;

final class WebhookRouteTest extends TestCase
{
    use InteractsWithMulla;

    public function testValidWebhookFiresBaseAndTypedEvents(): void
    {
        Event::fake();

        $this->postMullaWebhook('charge.success', ['amount' => 5000, 'metadata' => ['order_id' => 1001]])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        Event::assertDispatched(WebhookReceived::class, 1);
        Event::assertDispatched(ChargeSucceeded::class, static function (ChargeSucceeded $e): bool {
            return $e->event->amount() === 5000
                && $e->metadata('order_id') === 1001
                && $e->event->status() === 'SUCCESSFUL'
                && $e->event->objectId() !== null;
        });
    }

    public function testEachTypeMapsToItsEvent(): void
    {
        Event::fake();

        $this->postMullaWebhook('payout.failed')->assertOk();
        $this->postMullaWebhook('invoice.paid')->assertOk();

        Event::assertDispatched(PayoutFailed::class);
        Event::assertDispatched(InvoicePaid::class);
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    public function testUnknownTypeFiresOnlyTheBaseEvent(): void
    {
        Event::fake();

        $this->postMullaWebhook('refund.created')->assertOk();

        Event::assertDispatched(WebhookReceived::class, static fn(WebhookReceived $e): bool => $e->event->type === 'refund.created');
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function testTamperedBodyIsRejected(): void
    {
        Event::fake();
        $signed = WebhookSigner::event('charge.success', ['charge' => 'c1', 'amount' => 100], self::WEBHOOK_SECRET);
        $tampered = str_replace('"amount":100', '"amount":999999', $signed['body']);

        $this->call('POST', '/mulla/webhook', [], [], [], $this->server($signed['headers']), $tampered)
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid Mulla signature.']);

        $this->assertNoMullaEvents();
    }

    public function testMissingSignatureIsRejected(): void
    {
        Event::fake();

        $this->postJson('/mulla/webhook', ['event' => 'charge.success', 'event_id' => 'e1', 'data' => []])
            ->assertStatus(400);

        $this->assertNoMullaEvents();
    }

    public function testWrongSecretIsRejected(): void
    {
        Event::fake();
        $signed = WebhookSigner::event('charge.success', [], 'some-other-secret');

        $this->call('POST', '/mulla/webhook', [], [], [], $this->server($signed['headers']), $signed['body'])
            ->assertStatus(400);

        $this->assertNoMullaEvents();
    }

    public function testCurrentServerFormatWithOnlyV1HeaderIsAccepted(): void
    {
        Event::fake();
        $body = (string) json_encode(['event' => 'charge.success', 'event_id' => 'evt_v1', 'data' => ['charge' => 'c1']]);

        $this->call('POST', '/mulla/webhook', [], [], [], $this->server(WebhookSigner::v1Headers($body, self::WEBHOOK_SECRET)), $body)
            ->assertOk();

        Event::assertDispatched(ChargeSucceeded::class);
    }

    public function testRedeliveredEventIsAcknowledgedButNotDispatchedTwice(): void
    {
        Event::fake();

        $this->postMullaWebhook('charge.success', [], 'evt_same')->assertOk()->assertJson(['status' => 'ok']);
        $this->postMullaWebhook('charge.success', [], 'evt_same')->assertOk()->assertJson(['status' => 'duplicate']);

        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
    }

    public function testListenerFailureReturns500AndAllowsRedelivery(): void
    {
        $calls = 0;
        Event::listen(ChargeSucceeded::class, static function () use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('Database down');
            }
        });

        $this->postMullaWebhook('charge.success', [], 'evt_retry')->assertStatus(500);
        $this->postMullaWebhook('charge.success', [], 'evt_retry')->assertOk()->assertJson(['status' => 'ok']);

        self::assertSame(2, $calls);
    }

    public function testMissingWebhookSecretNeverAcceptsEvents(): void
    {
        Event::fake();
        $signed = WebhookSigner::event('charge.success', [], 'anything');
        config(['mulla.webhook.secret' => null]);

        $this->call('POST', '/mulla/webhook', [], [], [], $this->server($signed['headers']), $signed['body'])
            ->assertStatus(500);

        $this->assertNoMullaEvents();
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->get('/mulla/webhook')->assertStatus(405);
    }

    public function testRouteIsNamedAndHasNoCsrf(): void
    {
        $route = Route::getRoutes()->getByName('mulla.webhook');

        self::assertNotNull($route);
        self::assertSame('mulla/webhook', $route->uri());
        self::assertNotContains('web', $route->gatherMiddleware());
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
