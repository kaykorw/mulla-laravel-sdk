<?php

declare(strict_types=1);

/*
 * Example controller: app/Http/Controllers/CheckoutController.php
 *
 *     Route::post('/orders/{order}/pay', [CheckoutController::class, 'direct']);
 *     Route::post('/orders/{order}/checkout', [CheckoutController::class, 'hosted']);
 */

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Mulla\Exceptions\ConnectionException;
use Mulla\Exceptions\InvalidRequestException;
use Mulla\MullaClient;
use Mulla\Params\CreateCheckoutParams;
use Mulla\Params\InitiateChargeParams;
use Mulla\Support\Uuid;

final class CheckoutController
{
    public function __construct(private readonly MullaClient $mulla) {}

    /** Direct charge: Mulla sends a payment prompt to the customer's phone. */
    public function direct(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:MTN_RW,AIRTEL_RW'],
            'phone_number' => ['required', 'regex:/^2507\d{8}$/'],
        ]);

        // One idempotency key per order, stored, so a double click never charges twice.
        $order->mulla_idempotency_key ??= Uuid::v4();
        $order->save();

        try {
            $charge = $this->mulla->charges->initiate(
                InitiateChargeParams::make()
                    ->provider($data['provider'])
                    ->phoneNumber($data['phone_number'])
                    ->amount($order->total)
                    ->reason("Order #{$order->id}")
                    ->metadata(['order_id' => $order->id]),
                ['idempotency_key' => $order->mulla_idempotency_key],
            );
        } catch (InvalidRequestException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        } catch (ConnectionException $e) {
            // The prompt may already be on the customer's phone: wait for the webhook instead of retrying.
            return redirect()->route('orders.show', $order)->with('status', 'Checking your payment…');
        }

        $order->update(['mulla_charge_id' => $charge->id]);

        return redirect()->route('orders.show', $order)->with('status', 'Approve the payment on your phone.');
    }

    /** Hosted checkout: redirect the customer to Mulla's payment page. */
    public function hosted(Order $order): RedirectResponse
    {
        $session = $this->mulla->charges->createCheckout(
            CreateCheckoutParams::make()
                ->amount($order->total)
                ->successUrl(route('orders.show', $order))
                ->cancelUrl(route('cart'))
                ->metadata(['order_id' => $order->id]),
        );

        $order->update(['mulla_charge_id' => $session->charge]);

        // Return URLs are not signed: the order is marked paid by the ChargeSucceeded listener, not here.
        return redirect()->away((string) $session->checkoutUrl);
    }
}
