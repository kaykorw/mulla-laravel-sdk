<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * A charge failed or was declined (`charge.failed`).
 */
final class ChargeFailed extends WebhookReceived {}
