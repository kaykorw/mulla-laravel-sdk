<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * A test event sent from the dashboard or mulla:webhook:test (`webhook.test`).
 */
final class WebhookTested extends WebhookReceived {}
