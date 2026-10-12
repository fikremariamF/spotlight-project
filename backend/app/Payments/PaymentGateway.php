<?php

namespace App\Payments;

use App\Models\Checkout;
use App\Models\Payment;

/**
 * An online payment provider. The provider's verify answer is the only thing that marks a payment
 * paid: redirects, callbacks and webhooks just prompt a verify.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Configured and allowed to take payments in this environment. */
    public function ready(): bool;

    /**
     * Start a hosted payment and return the page to send the customer to.
     *
     * @throws PaymentGatewayError
     */
    public function initialize(Payment $payment, Checkout $checkout): string;

    /**
     * Ask the provider what happened to this payment.
     *
     * @throws PaymentGatewayError when the provider cannot be reached or answers nonsense
     */
    public function verify(Payment $payment): VerifyResult;
}
