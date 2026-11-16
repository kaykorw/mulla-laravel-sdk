<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

use Mulla\Webhooks\Event;

/**
 * Fired for every verified Mulla webhook, before the typed event (ChargeSucceeded, …).
 * Listen to this to log or audit all events; listen to the typed events for business logic.
 *
 * $event->event is the SDK event: ->type, ->id, ->data, ->objectId(), ->reference(),
 * ->amount(), ->metadata(), ->isTest().
 */
class WebhookReceived
{
    public function __construct(public readonly Event $event) {}

    /** Shortcut for $this->event->metadata()[$key], e.g. 'order_id'. */
    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->event->metadata()[$key] ?? $default;
    }
}
