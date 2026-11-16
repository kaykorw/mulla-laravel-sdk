<?php

declare(strict_types=1);

namespace Mulla\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Log\LogManager;
use Mulla\Exceptions\InvalidArgumentException;
use Mulla\Laravel\Exceptions\MullaConfigurationException;
use Mulla\MullaClient;

/**
 * Factory: turns config/mulla.php into a ready MullaClient.
 */
final class ClientFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly ?LogManager $log = null,
    ) {}

    /**
     * @param array<string, mixed> $overrides raw MullaClient options that win over config (e.g. a test transport)
     *
     * @throws MullaConfigurationException
     */
    public function make(array $overrides = []): MullaClient
    {
        $key = $this->config->get('mulla.secret_key');
        if (!is_string($key) || trim($key) === '') {
            throw MullaConfigurationException::missingSecretKey();
        }

        try {
            return new MullaClient($key, $overrides + $this->options());
        } catch (InvalidArgumentException $e) {
            throw MullaConfigurationException::invalid($e->getMessage(), $e);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $options = [
            'baseUrl' => $this->stringOrNull('mulla.base_url'),
            'timeout' => (float) $this->config->get('mulla.timeout', 30),
            'connectTimeout' => (float) $this->config->get('mulla.connect_timeout', 10),
            'maxRetries' => (int) $this->config->get('mulla.max_retries', 2),
            'lang' => $this->stringOrNull('mulla.lang'),
            'deviceId' => $this->stringOrNull('mulla.device_id'),
            'appInfo' => Version::USER_AGENT,
        ];

        $channel = $this->stringOrNull('mulla.log_channel');
        if ($channel !== null && $this->log !== null) {
            $options['logger'] = $this->log->channel($channel);
        }

        return array_filter($options, static fn(mixed $value): bool => $value !== null);
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
