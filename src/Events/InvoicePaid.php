<?php

declare(strict_types=1);

namespace Mulla\Laravel\Events;

/**
 * An invoice was paid (`invoice.paid`).
 */
final class InvoicePaid extends WebhookReceived {}
