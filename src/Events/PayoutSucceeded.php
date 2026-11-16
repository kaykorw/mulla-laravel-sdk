<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * A payout reached the recipient (`payout.success`).
 */
final class PayoutSucceeded extends WebhookReceived {}
