<?php

declare(strict_types=1);

namespace Mulla\Laravel\Console;

use Illuminate\Console\Command;
use Mulla\Exceptions\MullaException;
use Mulla\MullaClient;

/**
 * php artisan mulla:webhook:test
 *
 * Asks Mulla to send a `webhook.test` event to your configured endpoint and shows the result.
 */
final class WebhookTestCommand extends Command
{
    protected $signature = 'mulla:webhook:test';

    protected $description = 'Ask Mulla to send a test event to your webhook endpoint';

    public function handle(): int
    {
        try {
            /** @var MullaClient $mulla */
            $mulla = $this->laravel->make(MullaClient::class);
            $endpoint = $mulla->webhookEndpoint->retrieve();
            $this->components->twoColumnDetail('Endpoint', (string) $endpoint->url);

            $result = $mulla->webhookEndpoint->test();
        } catch (MullaException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Your endpoint answered', $result->statusCode !== null ? (string) $result->statusCode : '-');
        if ($result->error !== null) {
            $this->components->twoColumnDetail('Error', $result->error);
        }

        if (!$result->ok) {
            $this->components->error('The test event was not accepted. Check MULLA_WEBHOOK_SECRET and that the URL is publicly reachable.');

            return self::FAILURE;
        }

        $this->components->info('Your endpoint received and accepted the test event.');

        return self::SUCCESS;
    }
}
