<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * A customer paid a charge (`charge.success`).
 */
final class ChargeSucceeded extends WebhookReceived {}
