<?php

declare(strict_types=1);

namespace Mulla\Laravel\Tests\Feature;

use Mulla\Http\Request;
use Mulla\Laravel\Exceptions\MullaConfigurationException;
use Mulla\Laravel\Facades\Mulla;
use Mulla\MullaClient;
use Mulla\Services\ChargeService;

final class ClientTest extends TestCase
{
    public function testResolvesAConfiguredClient(): void
    {
        $client = $this->app->make(MullaClient::class);

        self::assertFalse($client->isLive());
        self::assertSame('https://mfs.kayko.dev/gateway/v1', $client->baseUrl());
        self::assertSame($client, $this->app->make('mulla'));
        self::assertSame($client, $this->app->make(MullaClient::class));
    }

    public function testRespectsBaseUrlAndOptions(): void
    {
        config([
            'mulla.base_url' => 'http://localhost:5605/gateway',
            'mulla.lang' => 'fr',
            'mulla.max_retries' => 0,
        ]);

        $client = $this->app->make(MullaClient::class);

        self::assertSame('http://localhost:5605/gateway/v1', $client->baseUrl());
        self::assertSame('fr', $client->config()->lang);
    }

    public function testMissingKeyFailsOnUseWithAHelpfulMessage(): void
    {
        config(['mulla.secret_key' => null]);

        $this->expectException(MullaConfigurationException::class);
        $this->expectExceptionMessage('MULLA_SECRET_KEY');
        $this->app->make(MullaClient::class);
    }

    public function testInvalidConfigIsReportedAsConfigurationError(): void
    {
        config(['mulla.base_url' => 'http://api.example.com/gateway']);

        $this->expectException(MullaConfigurationException::class);
        $this->expectExceptionMessage('https');
        $this->app->make(MullaClient::class);
    }

    public function testFacadeExposesServicesAsMethods(): void
    {
        self::assertInstanceOf(ChargeService::class, Mulla::charges());
        self::assertSame($this->app->make(MullaClient::class)->charges, Mulla::charges());
        self::assertFalse(Mulla::isLive());
    }

    public function testFakeRecordsRequestsWithoutNetwork(): void
    {
        $fake = Mulla::fake();
        $fake->pushJson(['id' => 'ch_1', 'reference' => 'CHG1', 'amount' => 5000, 'status' => 'PENDING']);

        $charge = Mulla::charges()->initiate(['provider' => 'MTN_RW', 'amount' => 5000, 'phone_number' => '250788000000']);

        self::assertSame('CHG1', $charge->reference);
        self::assertCount(1, $fake->requests());
        $request = $fake->lastRequest();
        self::assertInstanceOf(Request::class, $request);
        self::assertStringEndsWith('/charges/initiate', $request->url);
        self::assertStringContainsString('mulla-laravel/', (string) $request->header('user-agent'));
        // Dependency injection gets the same fake client.
        self::assertSame(Mulla::getFacadeRoot(), $this->app->make(MullaClient::class));
    }

    public function testFakeWorksWithoutAConfiguredKey(): void
    {
        config(['mulla.secret_key' => null]);

        $fake = Mulla::fake();
        $fake->pushJson(['data' => [], 'total' => 0, 'page' => 1, 'limit' => 10, 'total_pages' => 0]);

        self::assertSame([], Mulla::charges()->list()->data);
    }
}
