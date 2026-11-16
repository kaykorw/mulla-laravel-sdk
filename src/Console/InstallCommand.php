<?php

declare(strict_types=1);

namespace Mulla\Laravel\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'mulla:install
        {--secret-key= : Your sk_test_… or sk_live_… key (written to .env only)}
        {--webhook-secret= : Your webhook secret (written to .env only)}';

    protected $description = 'Publish the Mulla config and add the MULLA_* keys to your .env';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'mulla-config']);
        $this->components->info('Published config/mulla.php (existing file kept).');

        $secretKey = $this->stringOption('secret-key');
        if ($secretKey !== null && preg_match('/^sk_(test|live)_/', $secretKey) !== 1) {
            $this->components->error('The secret key must start with sk_test_ or sk_live_ (the pk_ key is not a secret key).');

            return self::FAILURE;
        }

        $values = [
            'MULLA_SECRET_KEY' => $secretKey ?? '',
            'MULLA_WEBHOOK_SECRET' => $this->stringOption('webhook-secret') ?? '',
        ];

        $envPath = $this->envPath();
        $this->writeEnv($envPath, $values);
        $this->writeEnv($envPath . '.example', array_fill_keys(array_keys($values), ''));

        /** @var \Illuminate\Contracts\Routing\UrlGenerator $url */
        $url = $this->laravel->make('url');
        $webhookUrl = rtrim($url->to('/'), '/') . '/' . ltrim((string) config('mulla.webhook.path', 'mulla/webhook'), '/');

        $this->newLine();

        return self::SUCCESS;
    }

    /** @param array<string, string> $values */
    private function writeEnv(string $path, array $values): void
    {
        if (!is_file($path)) {
            if (str_ends_with($path, '.example')) {
                return;
            }
            touch($path);
        }

        $contents = (string) file_get_contents($path);
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $added = [];
        $filled = [];

        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
            if (preg_match($pattern, $contents, $match) === 1) {
                if (trim($match[1], " \t\"'") === '' && $value !== '') {
                    $contents = (string) preg_replace($pattern, $key . '=' . $value, $contents, 1);
                    $filled[] = $key;
                }

                continue;
            }

            $contents = rtrim($contents, "\r\n") . ($contents === '' ? '' : $eol) . $key . '=' . $value . $eol;
            $added[] = $key;
        }

        if ($added === [] && $filled === []) {
            $this->components->info(basename($path) . ': Mulla keys already present.');

            return;
        }

        if (!str_contains($contents, '# Mulla') && $added !== []) {
            $contents = (string) preg_replace('/^' . preg_quote($added[0], '/') . '=/m', '# Mulla' . $eol . $added[0] . '=', $contents, 1);
        }

        file_put_contents($path, $contents);
        $this->components->info(basename($path) . ': ' . implode(', ', array_merge(
            array_map(static fn(string $k): string => "added {$k}", $added),
            array_map(static fn(string $k): string => "set {$k}", $filled),
        )) . '.');
    }

    private function envPath(): string
    {
        $app = $this->laravel;

        return method_exists($app, 'environmentFilePath') ? $app->environmentFilePath() : $app->basePath('.env');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
