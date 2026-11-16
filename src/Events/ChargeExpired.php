<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * A charge expired before the customer approved it (`charge.expired`).
 */
final class ChargeExpired extends WebhookReceived {}
