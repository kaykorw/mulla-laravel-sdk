# Mulla for Laravel

Official Laravel integration for [Mulla](https://mulla.app): accept MTN MoMo and Airtel Money
payments in Rwanda, send payouts, and react to payments with ordinary Laravel events.

- Configured from `.env`, injectable `MullaClient` and a `Mulla` facade
- Verified, de-duplicated webhooks delivered as events (`ChargeSucceeded`, `PayoutFailed`, …)
- `Mulla::fake()`, `postMullaWebhook()` and `mulla:webhook:simulate` for testing without a network or tunnel
- Built on [`kayko/mulla-php`](https://github.com/kaykorw/mulla-php-sdk): every SDK feature is available

Requires PHP 8.1+ and Laravel 10, 11 or 12.

## Installation

```bash
composer require kayko/mulla-laravel
php artisan mulla:install
```

`mulla:install` publishes `config/mulla.php` and adds the keys to your `.env`:

```dotenv
MULLA_SECRET_KEY=sk_test_...      # Mulla dashboard → Developers → API keys
MULLA_WEBHOOK_SECRET=...          # shown when you create the webhook endpoint
```

`sk_test_` keys use the sandbox, `sk_live_` keys production. No URL to configure.

## Taking a payment

Inject the client (recommended, easy to test) or use the facade. Both are the same instance.

```php
use Mulla\MullaClient;
use Mulla\Support\Uuid;

class CheckoutController
{
    public function __construct(private MullaClient $mulla) {}

    public function pay(Order $order)
    {
        $charge = $this->mulla->charges->initiate([
            'provider'     => 'MTN_RW',            // or AIRTEL_RW
            'phone_number' => '250788000000',
            'amount'       => $order->total,        // RWF
            'reason'       => "Order #{$order->id}",
            'metadata'     => ['order_id' => $order->id],
        ], ['idempotency_key' => $order->mulla_idempotency_key ??= Uuid::v4()]);

        $order->update(['mulla_charge_id' => $charge->id]);
    }
}
```

```php
use Mulla\Laravel\Facades\Mulla;

$session = Mulla::charges()->createCheckout([
    'amount' => 5000, 'success_url' => route('orders.show', $order), 'cancel_url' => route('cart'),
]);
return redirect()->away($session->checkoutUrl);

Mulla::payouts()->list(['status' => 'COMPLETED']);
```

Every service of the PHP SDK works the same way: `charges`, `checkout`, `payouts`, `recharges`,
`invoices`, `customers`, `webhookEndpoint`, `referenceData`. See the
[PHP SDK README](https://github.com/kaykorw/mulla-php-sdk#readme) for all methods, models and errors.

See [`examples/`](examples) for a full checkout controller and listener.

## Webhooks

The webhook route is registered for you at **`POST /mulla/webhook`**. In the dashboard, create a
webhook endpoint with your full URL (e.g. `https://shop.example/mulla/webhook`) and put its secret
in `MULLA_WEBHOOK_SECRET`.

Every request is verified against the raw body before your code runs. Invalid signatures get a
`400` and never reach your listeners. Mulla retries deliveries, so each event id is processed
only once.

Listen for the event you care about:

```php
use Mulla\Laravel\Events\ChargeSucceeded;

class MarkOrderPaid implements ShouldQueue
{
    public function handle(ChargeSucceeded $webhook): void
    {
        $event = $webhook->event;   // Mulla\Webhooks\Event

        $order = Order::where('mulla_charge_id', $event->objectId())->firstOrFail();
        $order->markPaid($event->reference(), $event->amount());
    }
}
```

| Event class | Mulla event |
|---|---|
| `ChargeSucceeded` | `charge.success` |
| `ChargeFailed` | `charge.failed` |
| `ChargeExpired` | `charge.expired` |
| `PayoutSucceeded` | `payout.success` |
| `PayoutFailed` | `payout.failed` |
| `InvoicePaid` | `invoice.paid` |
| `WebhookTested` | `webhook.test` |
| `WebhookReceived` | every event, fired first (good for logging or auditing) |

Each event exposes `$webhook->event` with `type`, `id`, `data`, `objectId()`, `reference()`,
`amount()`, `currency()`, `status()`, `metadata()` and `isTest()`.

If a listener throws, the endpoint returns `500` and Mulla delivers the event again later.
Make slow work queued (`ShouldQueue`) so the endpoint answers quickly.

### Custom route

Turn off automatic registration and place the route yourself:

```php
// config/mulla.php → 'webhook' => ['auto_register' => false, ...]

// routes/api.php
Route::mullaWebhooks('payments/mulla');   // POST /api/payments/mulla
```

The route has no CSRF protection by default. If you register it inside the `web` group, exclude
its path from CSRF verification.

### Checking your setup

```bash
php artisan mulla:webhook:test                         # Mulla sends a test event to your public URL
php artisan mulla:webhook:simulate charge.success --amount=5000 --order=1001   # local, no tunnel
```

`mulla:webhook:simulate` signs a realistic event with your secret and sends it through your own
route, so your listeners run exactly as in production. It is disabled in production.

## Testing your application

```php
use Mulla\Laravel\Facades\Mulla;
use Mulla\Laravel\Testing\InteractsWithMulla;

class CheckoutTest extends TestCase
{
    use InteractsWithMulla;

    public function test_customer_gets_a_payment_prompt(): void
    {
        $mulla = $this->fakeMulla();      // or Mulla::fake()
        $mulla->pushJson(['id' => 'ch_1', 'status' => 'PENDING', 'amount' => 5000]);

        $this->post('/orders/1/pay', ['provider' => 'MTN_RW', 'phone_number' => '250788000000']);

        $mulla->assertSent(fn ($request) => str_ends_with($request->url, '/charges/initiate'));
    }

    public function test_order_is_paid_when_mulla_confirms(): void
    {
        $order = Order::factory()->create(['mulla_charge_id' => 'ch_1']);

        $this->postMullaWebhook('charge.success', ['charge' => 'ch_1', 'amount' => $order->total])
            ->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
    }
}
```

`fakeMulla()` never touches the network and works without a real key. `postMullaWebhook()` signs
the event with your configured secret (or a test one) and posts it to the webhook route.

## Configuration

All options live in `config/mulla.php`, each with an `.env` variable:

| Key | `.env` | Default |
|---|---|---|
| `secret_key` | `MULLA_SECRET_KEY` | required |
| `base_url` | `MULLA_BASE_URL` | official gateway for the key (must be `https`, except localhost) |
| `timeout` / `connect_timeout` | `MULLA_TIMEOUT` / `MULLA_CONNECT_TIMEOUT` | 30 / 10 seconds |
| `max_retries` | `MULLA_MAX_RETRIES` | 2 (writes are never retried if they may have been processed) |
| `lang` | `MULLA_LANG` | API default (`en`, `fr`, `rw`, `sw`) |
| `log_channel` | `MULLA_LOG_CHANNEL` | none: one line per request; secrets are never logged |
| `webhook.secret` | `MULLA_WEBHOOK_SECRET` | required for webhooks |
| `webhook.path` | `MULLA_WEBHOOK_PATH` | `mulla/webhook` |
| `webhook.middleware` | | `[]` (add e.g. `throttle:120,1`) |
| `webhook.dedupe_ttl` | | 86400 seconds |
| `webhook.cache_store` | `MULLA_WEBHOOK_CACHE_STORE` | default cache store |

A missing secret key only fails when Mulla is used (with a message telling you what to set), so
`artisan` commands keep working. Run `php artisan config:clear` after changing `.env` if your
config is cached.

## Development

```bash
composer install
composer test       # PHPUnit + Testbench
composer analyse    # PHPStan (Larastan) level 8
composer cs         # code style
```


