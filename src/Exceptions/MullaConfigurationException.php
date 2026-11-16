<?php

declare(strict_types=1);

namespace Mulla\Laravel\Exceptions;

use Mulla\Exceptions\MullaException;

/**
 * The Laravel integration is not configured (missing or invalid .env values).
 */
final class MullaConfigurationException extends MullaException
{
    public static function missingSecretKey(): self
    {
        return new self(
            'Mulla is not configured: set MULLA_SECRET_KEY in your .env (an sk_test_… key from the Mulla dashboard), '
            . 'then run "php artisan config:clear". Run "php artisan mulla:install" to add the keys.',
        );
    }

    public static function missingWebhookSecret(): self
    {
        return new self(
            'Mulla webhook secret is not configured: set MULLA_WEBHOOK_SECRET in your .env to the secret shown when you '
            . 'created or rotated your webhook endpoint, then run "php artisan config:clear".',
        );
    }

    public static function invalid(string $reason, \Throwable $previous): self
    {
        return new self('Mulla is misconfigured: ' . $reason, 0, $previous);
    }
}
