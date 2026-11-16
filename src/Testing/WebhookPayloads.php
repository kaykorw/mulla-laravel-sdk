<?php

declare(strict_types=1);

namespace Mulla\Laravel\Testing;

use Mulla\Enums\WebhookEventType;
use Mulla\Support\Uuid;

/**
 * Realistic `data` objects for each webhook type, shaped exactly like the ones Mulla sends today.
 * Used by mulla:webhook:simulate and InteractsWithMulla::postMullaWebhook().
 *
 * Pass extra fields through $overrides, e.g. ['metadata' => ['order_id' => 1001]] once your
 * integration relies on them.
 */
final class WebhookPayloads
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $overrides merged over the defaults
     * @return array<string, mixed>
     */
    public static function for(WebhookEventType|string $type, array $overrides = []): array
    {
        $type = $type instanceof WebhookEventType ? $type->value : $type;
        $payment = [
            'payment' => Uuid::v4(),
            'transaction_id' => self::reference(),
            'amount' => 1000,
            'currency' => 'RWF',
        ];

        $data = match (true) {
            str_starts_with($type, 'charge.') => ['charge' => Uuid::v4()] + $payment + [
                'reference' => 'CHG' . self::reference(),
                'status' => match ($type) {
                    'charge.success' => 'SUCCESSFUL',
                    'charge.expired' => 'EXPIRED',
                    default => 'FAILED',
                },
            ],
            str_starts_with($type, 'payout.') => ['payout' => Uuid::v4()] + $payment + [
                'status' => $type === 'payout.success' ? 'SUCCESSFUL' : 'FAILED',
            ],
            $type === 'invoice.paid' => ['invoice' => Uuid::v4()] + $payment + ['status' => 'PAID'],
            default => ['message' => 'Test webhook from Mulla'],
        };

        return array_replace($data, $overrides);
    }

    private static function reference(): string
    {
        return strtoupper(bin2hex(random_bytes(5)));
    }
}
