<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

use Mulla\Enums\WebhookEventType;
use Mulla\Webhooks\Event;

/**
 * Factory: maps a Mulla event type to its Laravel event class.
 */
final class EventMap
{
    /** @var array<string, class-string<WebhookReceived>> */
    private const MAP = [
        WebhookEventType::CHARGE_SUCCESS->value => ChargeSucceeded::class,
        WebhookEventType::CHARGE_FAILED->value => ChargeFailed::class,
        WebhookEventType::CHARGE_EXPIRED->value => ChargeExpired::class,
        WebhookEventType::PAYOUT_SUCCESS->value => PayoutSucceeded::class,
        WebhookEventType::PAYOUT_FAILED->value => PayoutFailed::class,
        WebhookEventType::INVOICE_PAID->value => InvoicePaid::class,
        WebhookEventType::WEBHOOK_TEST->value => WebhookTested::class,
    ];

    private function __construct() {}

    /** The typed event for this webhook, or null for event types this version does not know. */
    public static function typed(Event $event): ?WebhookReceived
    {
        $class = self::MAP[$event->type] ?? null;

        return $class !== null ? new $class($event) : null;
    }

    /** @return array<string, class-string<WebhookReceived>> */
    public static function all(): array
    {
        return self::MAP;
    }
}
