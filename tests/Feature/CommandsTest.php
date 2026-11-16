<?php

declare(strict_types=1);

namespace Mulla\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mulla\Laravel\Events\ChargeSucceeded;
use Mulla\Laravel\Events\PayoutSucceeded;
use Mulla\Laravel\Facades\Mulla;

final class CommandsTest extends TestCase
{
    private string $envPath;
    private ?string $originalEnv = null;
    private ?string $originalExample = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = $this->app->environmentFilePath();
        $this->originalEnv = is_file($this->envPath) ? (string) file_get_contents($this->envPath) : null;
        $this->originalExample = is_file($this->envPath . '.example') ? (string) file_get_contents($this->envPath . '.example') : null;
    }

    protected function tearDown(): void
    {
        $this->restore($this->envPath, $this->originalEnv);
        $this->restore($this->envPath . '.example', $this->originalExample);
        @unlink($this->app->configPath('mulla.php'));
        parent::tearDown();
    }

    public function testInstallAddsKeysWithoutOverwriting(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Shop\nMULLA_WEBHOOK_SECRET=keep-me\n");
        file_put_contents($this->envPath . '.example', "APP_NAME=\n");

        $this->artisan('mulla:install', ['--secret-key' => 'sk_test_abc_def', '--webhook-secret' => 'new-value'])
            ->assertSuccessful();

        $env = (string) file_get_contents($this->envPath);
        self::assertStringContainsString('MULLA_SECRET_KEY=sk_test_abc_def', $env);
        self::assertStringContainsString('MULLA_WEBHOOK_SECRET=keep-me', $env);
        self::assertStringNotContainsString('new-value', $env);
        self::assertStringContainsString('APP_NAME=Shop', $env);

        $example = (string) file_get_contents($this->envPath . '.example');
        self::assertStringContainsString("MULLA_SECRET_KEY=\n", $example);
        self::assertStringNotContainsString('sk_test_abc_def', $example);
        self::assertFileExists($this->app->configPath('mulla.php'));
    }

    public function testInstallIsIdempotent(): void
    {
        file_put_contents($this->envPath, "MULLA_SECRET_KEY=\n");

        $this->artisan('mulla:install', ['--secret-key' => 'sk_test_abc_def'])->assertSuccessful();
        $this->artisan('mulla:install')->assertSuccessful();

        $env = (string) file_get_contents($this->envPath);
        self::assertSame(1, substr_count($env, 'MULLA_SECRET_KEY='));
        self::assertStringContainsString('MULLA_SECRET_KEY=sk_test_abc_def', $env);
    }

    public function testInstallRejectsPublicKey(): void
    {
        file_put_contents($this->envPath, '');

        $this->artisan('mulla:install', ['--secret-key' => 'pk_test_abc_def'])->assertFailed();
    }

    public function testSimulateRunsListenersThroughTheRoute(): void
    {
        Event::fake();

        $this->artisan('mulla:webhook:simulate', ['event' => 'charge.success', '--amount' => '5000', '--order' => '1001'])
            ->assertSuccessful();

        Event::assertDispatched(ChargeSucceeded::class, static fn(ChargeSucceeded $e): bool => $e->event->amount() === 5000 && $e->metadata('order_id') === 1001);
    }

    public function testSimulateAcceptsOtherEventTypes(): void
    {
        Event::fake();

        $this->artisan('mulla:webhook:simulate', ['event' => 'payout.success'])->assertSuccessful();

        Event::assertDispatched(PayoutSucceeded::class);
    }

    public function testSimulateIsDisabledInProduction(): void
    {
        Event::fake();
        $this->app['env'] = 'production';

        $this->artisan('mulla:webhook:simulate')->assertFailed();

        $this->assertNoMullaEvents();
    }

    public function testSimulateRequiresAWebhookSecret(): void
    {
        config(['mulla.webhook.secret' => '']);

        $this->artisan('mulla:webhook:simulate')->assertFailed();
    }

    public function testWebhookTestCommandReportsTheResult(): void
    {
        $fake = Mulla::fake();
        $fake->pushJson(['id' => 'wh_1', 'url' => 'https://shop.example/mulla/webhook', 'is_active' => true]);
        $fake->pushJson(['ok' => true, 'status_code' => 200]);

        $this->artisan('mulla:webhook:test')
            ->expectsOutputToContain('https://shop.example/mulla/webhook')
            ->assertSuccessful();
    }

    public function testWebhookTestCommandShowsApiErrors(): void
    {
        $fake = Mulla::fake();
        $fake->pushError(403, 'API_KEY_SCOPE_DENIED', 'API key does not have the required scope');

        $this->artisan('mulla:webhook:test')
            ->expectsOutputToContain('API_KEY_SCOPE_DENIED')
            ->assertFailed();
    }

    private function restore(string $path, ?string $contents): void
    {
        if ($contents === null) {
            @unlink($path);
        } else {
            file_put_contents($path, $contents);
        }
    }
}
