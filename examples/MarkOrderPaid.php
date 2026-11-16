<?php

declare(strict_types=1);

/*
 * Example listener: app/Listeners/MarkOrderPaid.php
 *
 * Laravel discovers listeners automatically from their type-hint (Laravel 11+).
 * On Laravel 10, register it in EventServiceProvider::$listen.
 */

namespace App\Listeners;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mulla\Laravel\Events\ChargeSucceeded;

final class MarkOrderPaid implements ShouldQueue
{
    public function handle(ChargeSucceeded $webhook): void
    {
        $event = $webhook->event;

        // We stored the charge id on the order when creating the payment (see CheckoutController).
        $order = Order::where('mulla_charge_id', $event->objectId())->first();
        if ($order === null || $order->status === 'paid') {
            return; // unknown or already handled: nothing to do
        }

        // Never trust the amount blindly: compare it with what the order expects.
        if ((int) $event->amount() !== (int) $order->total) {
            logger()->warning('Mulla amount mismatch', ['order' => $order->id, 'charge' => $event->objectId()]);

            return;
        }

        $order->update(['status' => 'paid', 'paid_at' => now(), 'mulla_reference' => $event->reference()]);
    }
}
