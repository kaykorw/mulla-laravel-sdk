# Mulla for Laravel

<!-- x-release-please-start-version -->

> This guide applies to `kayko/mulla-laravel` **0.0.1** and the Mulla API **v1**. See [Versioning and compatibility](#versioning-and-compatibility).

<!-- x-release-please-end -->

## Introduction

The official Laravel package for Mulla, the mobile money payment gateway for Rwanda. Take **MTN Mobile Money** and **Airtel Money** payments, send payouts, and react to payments with ordinary Laravel events.

What it adds on top of the [Mulla PHP SDK](https://github.com/kaykorw/mulla-php-sdk):

- **Configuration from `.env`**, an injectable `MullaClient` and a `Mulla` facade.
- **A ready webhook route** that verifies every request, skips duplicates and fires Laravel events such as `ChargeSucceeded`.
- **Testing tools:** `Mulla::fake()`, `postMullaWebhook()` and `php artisan mulla:webhook:simulate`, so you can test without a network or a tunnel.

Every feature of the PHP SDK works here too. This README covers the Laravel parts. For all services, models and exceptions, see the [PHP SDK README](https://github.com/kaykorw/mulla-php-sdk#readme).

## Table of contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [Guides](#guides)
  - [Charge a phone number](#charge-a-phone-number)
  - [Use the hosted checkout page](#use-the-hosted-checkout-page)
  - [Handle webhooks](#handle-webhooks)
  - [Use a custom webhook route](#use-a-custom-webhook-route)
  - [Use other Mulla services](#use-other-mulla-services)
  - [Test your application](#test-your-application)
  - [Go live](#go-live)
- [Reference](#reference)
  - [Configuration](#configuration)
  - [Laravel events](#laravel-events)
  - [Artisan commands](#artisan-commands)
  - [Testing helpers](#testing-helpers)
- [Troubleshooting](#troubleshooting)
- [Versioning and compatibility](#versioning-and-compatibility)
- [Support](#support)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- **PHP 8.2 or newer** and **Laravel 12 or 13**.
- **A Mulla merchant account** and a secret API key from the Mulla dashboard (Developers → API keys).
- **The right scopes on the key.** A scope is a permission. Each service needs its own: `CHARGES`, `PAYOUTS`, `INVOICES`, `RECHARGES` or `CUSTOMERS`.

## Installation

```bash
composer require kayko/mulla-laravel
php artisan mulla:install
```

`mulla:install` publishes `config/mulla.php` and adds these keys to your `.env`:

```dotenv
MULLA_SECRET_KEY=sk_test_...   # Mulla dashboard → Developers → API keys
MULLA_WEBHOOK_SECRET=...       # shown when you create the webhook endpoint
```

You can also pass them directly: `php artisan mulla:install --secret-key=sk_test_... --webhook-secret=...`. They are written to `.env` only.

## Quick start

This uses the sandbox, so no real money moves.

**1. Install** the package and set `MULLA_SECRET_KEY` to a test key (see [Installation](#installation)).

**2. Ask a customer to pay** from a controller. Laravel injects the client for you:

```php
use Mulla\MullaClient;

class PaymentController
{
    public function store(Order $order, MullaClient $mulla)
    {
        $charge = $mulla->charges->initiate([
            'provider'     => 'MTN_RW',
            'phone_number' => '250788000000',
            'amount'       => $order->total, // RWF
            'reason'       => "Order #{$order->id}",
        ]);

        $order->update(['mulla_charge_id' => $charge->id]);

        return back()->with('status', 'Approve the payment on your phone.');
    }
}
```

**3. React to the result** with a listener. Laravel discovers it automatically:

```php
use Mulla\Laravel\Events\ChargeSucceeded;

class MarkOrderPaid
{
    public function handle(ChargeSucceeded $webhook): void
    {
        Order::where('mulla_charge_id', $webhook->event->objectId())->firstOrFail()->markPaid();
    }
}
```

**4. Try it locally** without Mulla calling your machine. Use the charge ID from step 2:

```bash
php artisan mulla:webhook:simulate charge.success --amount=5000 --data='{"charge":"<charge id>"}'
```

## How it works

### Payments finish later, not instantly

The customer has to approve a mobile money payment on their phone. So a charge starts as `PENDING` and changes later to `SUCCESSFUL`, `FAILED` or `EXPIRED`. Mulla then calls your webhook route, and this package turns that call into a Laravel event.

```text
Your controller ──► Mulla ──► customer's phone
                                    │ approves
Your listener ◄── Laravel event ◄── POST /mulla/webhook ◄── Mulla
```

### Test mode and live mode

The key prefix decides where requests go. You don't need to configure a URL.

| Key prefix | Mode                   |
| ---------- | ---------------------- |
| `sk_test_` | Sandbox, no real money |
| `sk_live_` | Production, real money |

### The client and the facade

The injected `MullaClient` and the `Mulla` facade are the same instance. Injection is easier to test. The facade is shorter:

```php
use Mulla\Laravel\Facades\Mulla;

Mulla::charges()->retrieve($id); // same as $mulla->charges->retrieve($id)
```

### Amounts, phone numbers and field names

- **Amounts** are whole Rwandan francs (RWF), not cents. The minimum is 10.
- **Phone numbers** use the international format without `+`, for example `250788000000`.
- **Arrays use the API's field names** in snake_case (`phone_number`, `success_url`). The API rejects unknown fields.

## Guides

### Charge a phone number

Use this when your app already knows the customer's network and phone number. Save an idempotency key with the order so a retry never charges twice:

```php
use Mulla\MullaClient;
use Mulla\Support\Uuid;

public function pay(Order $order, MullaClient $mulla)
{
    $order->mulla_idempotency_key ??= Uuid::v4();
    $order->save();

    $charge = $mulla->charges->initiate([
        'provider'     => 'MTN_RW', // or AIRTEL_RW
        'phone_number' => '250788000000',
        'amount'       => $order->total,
        'reason'       => "Order #{$order->id}",
        'metadata'     => ['order_id' => $order->id],
    ], ['idempotency_key' => $order->mulla_idempotency_key]);

    $order->update(['mulla_charge_id' => $charge->id]);
}
```

See [`examples/CheckoutController.php`](examples/CheckoutController.php) for a full controller.

### Use the hosted checkout page

Use this when you don't want to build a payment form. Mulla's page asks for the network and phone number.

```php
use Mulla\Laravel\Facades\Mulla;

$session = Mulla::charges()->createCheckout([
    'amount'      => $order->total,
    'reason'      => "Order #{$order->id}",
    'success_url' => route('orders.show', $order),
    'cancel_url'  => route('cart'),
]);

$order->update(['mulla_charge_id' => $session->charge]);

return redirect()->away($session->checkoutUrl);
```

> **Don't trust the return URL alone.** Anyone can open `success_url` in a browser. Mark the order paid from the webhook, or check with `Mulla::charges()->retrieve($order->mulla_charge_id)`.

### Handle webhooks

The package registers **`POST /mulla/webhook`** for you. For every request it:

1. checks the signature against the raw body (invalid ones get `400` and never reach your code);
2. skips events it has already handled (Mulla may deliver an event more than once);
3. fires `WebhookReceived`, then the typed event such as `ChargeSucceeded`.

**1. Register your URL** in the Mulla dashboard (for example `https://shop.example/mulla/webhook`) and put the secret it shows in `MULLA_WEBHOOK_SECRET`.

**2. Write a listener** for each event you care about:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Mulla\Laravel\Events\ChargeSucceeded;

class MarkOrderPaid implements ShouldQueue
{
    public function handle(ChargeSucceeded $webhook): void
    {
        $event = $webhook->event; // Mulla\Webhooks\Event

        $order = Order::where('mulla_charge_id', $event->objectId())->firstOrFail();
        $order->markPaid($event->reference(), $event->amount());
    }
}
```

**3. Check the setup:**

```bash
php artisan mulla:webhook:test   # Mulla sends a test event to your public URL
```

The route replies:

| Reply | When                                              | What Mulla does next |
| ----- | ------------------------------------------------- | -------------------- |
| `200` | Your listeners finished, or it's a duplicate      | Nothing              |
| `400` | The signature is missing, wrong or too old        | Nothing              |
| `500` | A listener threw, or the webhook secret isn't set | Retries later        |

**Good to know:**

- **Find your record with `$webhook->event->objectId()`.** It returns the charge, payout or invoice ID. `metadata()` returns `null` when the payload has no metadata, so don't rely on it alone.
- **Queue slow work** (`ShouldQueue`) so the route answers quickly.
- **Queued listeners and retries:** once a listener is queued, the route has already answered `200`, so Mulla won't send the event again. If the job fails, Laravel's queue retries it, not Mulla. Synchronous listeners that throw get a `500`, and Mulla retries.
- **Duplicates are remembered in the cache** for 24 hours (`webhook.dedupe_ttl`). With several servers, use a shared cache store (Redis, database) via `MULLA_WEBHOOK_CACHE_STORE`.
- **Old webhooks are rejected.** A signed webhook more than 5 minutes old fails, which stops replayed requests. Keep your server clock correct.

### Use a custom webhook route

To choose the URL or the middleware yourself, turn off automatic registration and register the route in your routes file:

```php
// config/mulla.php
'webhook' => ['auto_register' => false, /* ... */],

// routes/api.php
Route::mullaWebhooks('payments/mulla'); // POST /api/payments/mulla
```

The automatic route has no CSRF protection. If you register it inside the `web` group, exclude its path from CSRF verification.

### Use other Mulla services

Every service of the PHP SDK is available on the client and the facade:

```php
Mulla::payouts()->initiate([
    'provider'     => 'MTN_RW',
    'amount'       => 15000,
    'phone_number' => '250788000000',
    'reason'       => 'Refund for order #1001',
]);

Mulla::invoices()->list(['status' => 'OVERDUE']);
Mulla::customers()->create(['full_name' => 'Jane Doe', 'email' => 'jane@example.com']);
```

The services are `charges`, `checkout`, `payouts`, `recharges`, `invoices`, `customers`, `webhookEndpoint` and `referenceData`. For their methods, statuses, exceptions, retries and idempotency, see the [PHP SDK README](https://github.com/kaykorw/mulla-php-sdk#readme).

### Test your application

Use the `InteractsWithMulla` trait in your tests. Nothing touches the network, and no real key is needed.

```php
use Mulla\Laravel\Testing\InteractsWithMulla;

class CheckoutTest extends TestCase
{
    use InteractsWithMulla;

    public function test_customer_gets_a_payment_prompt(): void
    {
        $mulla = $this->fakeMulla(); // or Mulla::fake()
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

To test failures, queue `$mulla->pushError(400, 'INSUFFICIENT_BALANCE')` or `$mulla->pushConnectionError()`.

### Go live

Before you switch to real money, check that:

- [ ] your merchant account is approved in the Mulla dashboard;
- [ ] `MULLA_SECRET_KEY` is a live key (`sk_live_`) with only the scopes you need;
- [ ] the webhook endpoint for the live key points to your production URL, and `MULLA_WEBHOOK_SECRET` holds its secret;
- [ ] `php artisan mulla:webhook:test` reaches your server over HTTPS;
- [ ] you ran `php artisan config:cache` (or `config:clear`) after changing `.env`;
- [ ] the cache store for duplicate checks is shared by all your servers;
- [ ] orders are marked paid only from a webhook or `retrieve()`, never from the return URL;
- [ ] if your key has an IP allowlist, your servers' IPs are on it.

## Reference

### Configuration

All options are in `config/mulla.php`, and most read an `.env` variable:

| Key                     | `.env` variable             | Default                                                                  |
| ----------------------- | --------------------------- | ------------------------------------------------------------------------ |
| `secret_key`            | `MULLA_SECRET_KEY`          | Required                                                                 |
| `base_url`              | `MULLA_BASE_URL`            | The official server for the key. Must use HTTPS, except for local hosts. |
| `timeout`               | `MULLA_TIMEOUT`             | `30` seconds                                                             |
| `connect_timeout`       | `MULLA_CONNECT_TIMEOUT`     | `10` seconds                                                             |
| `max_retries`           | `MULLA_MAX_RETRIES`         | `2`. Writes are never retried if Mulla may have processed them.          |
| `lang`                  | `MULLA_LANG`                | API default. One of `en`, `fr`, `rw`, `sw`.                              |
| `device_id`             | `MULLA_DEVICE_ID`           | Derived from the key                                                     |
| `log_channel`           | `MULLA_LOG_CHANNEL`         | None. When set, one line per request. Secrets are never logged.          |
| `webhook.secret`        | `MULLA_WEBHOOK_SECRET`      | Required for webhooks                                                    |
| `webhook.path`          | `MULLA_WEBHOOK_PATH`        | `mulla/webhook`                                                          |
| `webhook.auto_register` | —                           | `true`                                                                   |
| `webhook.middleware`    | —                           | `[]`. Add for example `throttle:120,1`.                                  |
| `webhook.tolerance`     | —                           | `300` seconds: the maximum age of a signed webhook                       |
| `webhook.dedupe_ttl`    | —                           | `86400` seconds: how long handled event IDs are remembered               |
| `webhook.cache_store`   | `MULLA_WEBHOOK_CACHE_STORE` | The default cache store                                                  |

A missing secret key only fails when Mulla is used, with a message that says what to set, so other `artisan` commands keep working.

### Laravel events

All events are in `Mulla\Laravel\Events`. Each has a public `$event` property (`Mulla\Webhooks\Event`) with `type`, `id`, `data`, `objectId()`, `reference()`, `amount()`, `currency()`, `status()`, `metadata()` and `isTest()`.

| Laravel event     | Mulla event      | Fired when                                |
| ----------------- | ---------------- | ----------------------------------------- |
| `WebhookReceived` | Every event      | First, for every event. Good for logging. |
| `ChargeSucceeded` | `charge.success` | A charge succeeds                         |
| `ChargeFailed`    | `charge.failed`  | A charge fails                            |
| `ChargeExpired`   | `charge.expired` | A charge expires without an answer        |
| `PayoutSucceeded` | `payout.success` | A payout completes                        |
| `PayoutFailed`    | `payout.failed`  | A payout fails                            |
| `InvoicePaid`     | `invoice.paid`   | An invoice is fully paid                  |
| `WebhookTested`   | `webhook.test`   | You run `mulla:webhook:test`              |

### Artisan commands

| Command                          | What it does                                                                                     |
| -------------------------------- | ------------------------------------------------------------------------------------------------ |
| `mulla:install`                  | Publishes the config and adds the `MULLA_*` keys to `.env`                                       |
| `mulla:webhook:test`             | Asks Mulla to send a `webhook.test` event to your registered URL                                 |
| `mulla:webhook:simulate {event}` | Signs a fake event with your secret and sends it through your own route. Disabled in production. |

`mulla:webhook:simulate` options:

- `event`: `charge.success` (default), `charge.failed`, `charge.expired`, `payout.success`, `payout.failed`, `invoice.paid` or `webhook.test`;
- `--amount=5000`: the amount in RWF;
- `--order=1001`: sets `metadata.order_id`;
- `--data='{"reference":"CHG123"}'`: a JSON object merged into the event data.

### Testing helpers

| Helper                                                     | What it does                                                                                  |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `$this->fakeMulla()` / `Mulla::fake()`                     | Swaps the HTTP layer for a `FakeTransport` and returns it. Queue responses with `pushJson()`. |
| `$this->postMullaWebhook($type, $data, $eventId)`          | Signs an event with your configured secret (or a test one) and posts it to the webhook route. |
| `assertSent()`, `assertSentCount()`, `assertNothingSent()` | Assertions on the requests your code sent (on the fake).                                      |

## Troubleshooting

| Problem                                                  | Likely cause and fix                                                                                         |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `Mulla is not configured: set MULLA_SECRET_KEY…`         | The key isn't in `.env`, or the config is cached. Set it, then run `php artisan config:clear`.               |
| Webhook returns `500` "Webhook secret is not configured" | Set `MULLA_WEBHOOK_SECRET`, then run `php artisan config:clear`.                                             |
| Webhook returns `400` "Invalid Mulla signature"          | Wrong secret. Test and live endpoints have different secrets, and rotating the secret replaces the old one.  |
| Webhook returns `419`                                    | You registered the route in the `web` group. Exclude its path from CSRF verification.                        |
| Webhook returns `404`                                    | The URL in the dashboard doesn't match `webhook.path`, or `auto_register` is off and no route is registered. |
| Listener never runs, but the route returns `200`         | The event was already handled (duplicate), or the listener isn't registered. Check `php artisan event:list`. |
| Queued listener didn't run                               | No queue worker is running. Start one with `php artisan queue:work`.                                         |
| `PermissionException` with `API_KEY_SCOPE_DENIED`        | The key lacks the scope for that service. Add it in the dashboard.                                           |
| `InvalidRequestException` with `VALIDATION_FAILED`       | A field is invalid, misspelled or unknown. Array keys must be snake_case, such as `phone_number`.            |

For other SDK errors, see the [PHP SDK troubleshooting table](https://github.com/kaykorw/mulla-php-sdk#troubleshooting).

## Versioning and compatibility

<!-- x-release-please-start-version -->

| Package version | Mulla PHP SDK | Laravel | Tested in CI on                                          |
| --------------- | ------------- | ------- | -------------------------------------------------------- |
| 0.0.1           | `^0.0.1`      | 12, 13  | PHP 8.2–8.4 with Laravel 12; PHP 8.3–8.5 with Laravel 13 |

<!-- x-release-please-end -->

- The package follows [semantic versioning](https://semver.org). Until 1.0.0, a minor release (0.**x**.0) may contain breaking changes. Patch releases never do.
- Every change is listed in [CHANGELOG.md](CHANGELOG.md).

## Support

- **Bugs and feature requests:** [open an issue](https://github.com/kaykorw/mulla-laravel-sdk/issues). Include the package version, your PHP and Laravel versions (`php artisan about`), and the error message. Never paste your API key or webhook secret.
- **Your account, payments or API keys:** contact Mulla support through your Mulla dashboard.
- **Security problems:** don't open a public issue. Report them privately through the repository's [Security tab](https://github.com/kaykorw/mulla-laravel-sdk/security).

## Contributing

Contributions are welcome. For anything bigger than a small fix, open an issue first so we can agree on the approach.

**1. Set up** (PHP 8.2 or newer and Composer):

```bash
git clone https://github.com/kaykorw/mulla-laravel-sdk.git
cd mulla-laravel-sdk
composer install
```

**2. Make your change**, with tests in `tests/`. Update this README if the public API changes. Changes to API calls themselves belong in the [PHP SDK](https://github.com/kaykorw/mulla-php-sdk).

**3. Check everything** before you open a pull request:

```bash
composer check   # code style, static analysis and tests
```

Other commands:

| Command            | What it does                           |
| ------------------ | -------------------------------------- |
| `composer test`    | Run the tests (PHPUnit with Testbench) |
| `composer analyse` | Static analysis (Larastan level 8)     |
| `composer cs`      | Check code style                       |
| `composer cs:fix`  | Fix code style                         |

**4. Open a pull request** against `main`. CI must pass.

**Rules:**

- **Support every Laravel version** listed in [Versioning and compatibility](#versioning-and-compatibility).
- **[Conventional Commits](https://www.conventionalcommits.org)** for commit messages (`feat:`, `fix:`, `docs:`, `chore:`). Releases and the changelog are generated from them, so `feat:` and `fix:` commits appear in the next release.

## License

MIT. See [LICENSE](LICENSE).
