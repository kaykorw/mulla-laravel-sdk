<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * A payout failed (`payout.failed`).
 */
final class PayoutFailed extends WebhookReceived {}
