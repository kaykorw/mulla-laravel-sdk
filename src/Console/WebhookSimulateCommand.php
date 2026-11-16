<?php

declare(strict_types=1);

namespace Mulla\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Mulla\Laravel\Events\EventMap;
use Mulla\Laravel\Testing\WebhookPayloads;
use Mulla\Testing\WebhookSigner;

/**
 * php artisan mulla:webhook:simulate charge.success --amount=5000 --order=1001
 *
 * Signs a realistic event with your webhook secret and sends it through your app's own
 * webhook route, in-process. Your listeners run exactly as for a real delivery, without a tunnel.
 */
final class WebhookSimulateCommand extends Command
{
    protected $signature = 'mulla:webhook:simulate
        {event=charge.success : charge.success, charge.failed, charge.expired, payout.success, payout.failed, invoice.paid, webhook.test}
        {--amount= : Amount in RWF}
        {--order= : Sets metadata.order_id}
        {--data= : JSON object merged into the event data, e.g. {"reference":"CHG123"}}';

    protected $description = 'Send a signed, fake Mulla webhook through your app (local development only)';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('mulla:webhook:simulate is disabled in production.');

            return self::FAILURE;
        }

        $secret = config('mulla.webhook.secret');
        if (!is_string($secret) || trim($secret) === '') {
            $this->components->error('Set MULLA_WEBHOOK_SECRET in your .env first (any value works locally), then run "php artisan config:clear".');

            return self::FAILURE;
        }

        $argument = $this->argument('event');
        $type = is_string($argument) && $argument !== '' ? $argument : 'charge.success';
        if (!array_key_exists($type, EventMap::all())) {
            $this->components->warn("\"{$type}\" is not a known Mulla event; only WebhookReceived will fire.");
        }

        $overrides = $this->overrides();
        if ($overrides === null) {
            return self::FAILURE;
        }

        $signed = WebhookSigner::event($type, WebhookPayloads::for($type, $overrides), trim($secret));
        $path = '/' . ltrim((string) config('mulla.webhook.path', 'mulla/webhook'), '/');

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($signed['headers'] as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        /** @var HttpKernel $kernel */
        $kernel = $this->laravel->make(HttpKernel::class);
        $request = Request::create($path, 'POST', [], [], [], $server, $signed['body']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $this->components->twoColumnDetail("POST {$path}", (string) $response->getStatusCode());
        $this->line('  ' . $signed['body']);
        $this->line('  → ' . $response->getContent());

        if (!$response->isSuccessful()) {
            $this->components->error('Your app did not accept the event. Check storage/logs for the listener error.');

            return self::FAILURE;
        }

        $this->components->info("Delivered {$type}.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function overrides(): ?array
    {
        $overrides = [];

        $json = $this->option('data');
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $this->components->error('--data must be a JSON object.');

                return null;
            }
            $overrides = $decoded;
        }

        $amount = $this->option('amount');
        if (is_string($amount) && $amount !== '') {
            if (!is_numeric($amount)) {
                $this->components->error('--amount must be a number.');

                return null;
            }
            $overrides['amount'] = $amount + 0;
        }

        $order = $this->option('order');
        if (is_string($order) && $order !== '') {
            $metadata = is_array($overrides['metadata'] ?? null) ? $overrides['metadata'] : [];
            $overrides['metadata'] = ['order_id' => ctype_digit($order) ? (int) $order : $order] + $metadata;
        }

        return $overrides;
    }
}
