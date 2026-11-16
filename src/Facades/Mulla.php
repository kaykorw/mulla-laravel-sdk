<?php

declare(strict_types=1);

namespace Mulla\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Mulla\Laravel\ClientFactory;
use Mulla\MullaClient;
use Mulla\Services\ChargeService;
use Mulla\Services\CheckoutService;
use Mulla\Services\CustomerService;
use Mulla\Services\InvoiceService;
use Mulla\Services\PayoutService;
use Mulla\Services\RechargeService;
use Mulla\Services\ReferenceDataService;
use Mulla\Services\WebhookEndpointService;
use Mulla\Testing\FakeTransport;

/**
 * Static access to the configured MullaClient.
 *
 * Prefer injecting Mulla\MullaClient in classes you test; both use the same instance.
 *
 * @method static ChargeService          charges()
 * @method static CheckoutService        checkout()
 * @method static PayoutService          payouts()
 * @method static RechargeService        recharges()
 * @method static InvoiceService         invoices()
 * @method static CustomerService        customers()
 * @method static WebhookEndpointService webhookEndpoint()
 * @method static ReferenceDataService   referenceData()
 * @method static \Mulla\Environment\Environment environment()
 * @method static bool                   isLive()
 * @method static string                 baseUrl()
 * @method static \Mulla\MullaConfig     config()
 *
 * @see MullaClient
 */
final class Mulla extends Facade
{
    /** MullaClient exposes services as properties; the facade exposes them as methods. */
    private const SERVICES = [
        'charges', 'checkout', 'payouts', 'recharges', 'invoices', 'customers', 'webhookEndpoint', 'referenceData',
    ];

    /**
     * Replace the client with one that never touches the network, and return its transport
     * so you can queue responses and assert on requests:
     */
    public static function fake(): FakeTransport
    {
        $transport = new FakeTransport();
        $app = static::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('Mulla::fake() needs a booted Laravel application.');
        }

        $config = $app->make('config');
        if (!is_string($config->get('mulla.secret_key')) || $config->get('mulla.secret_key') === '') {
            $config->set('mulla.secret_key', 'sk_test_fake_fakeSecretForTests');
        }

        static::swap($app->make(ClientFactory::class)->make([
            'transport' => $transport,
            'sleeper' => static function (float $seconds): void {},
        ]));

        return $transport;
    }

    /**
     * @param string       $method
     * @param array<mixed> $args
     */
    public static function __callStatic($method, $args): mixed
    {
        if ($args === [] && in_array($method, self::SERVICES, true)) {
            /** @var MullaClient $client */
            $client = static::getFacadeRoot();

            return $client->{$method};
        }

        return parent::__callStatic($method, $args);
    }

    protected static function getFacadeAccessor(): string
    {
        return MullaClient::class;
    }
}
