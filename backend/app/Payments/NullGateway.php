<?php

namespace App\Payments;

use App\Models\Checkout;
use App\Models\Payment;

/** Online payment switched off (MARKETPLACE_PAYMENT_GATEWAY=none, or a gateway that is not allowed here). */
class NullGateway implements PaymentGateway
{
    public function __construct(private readonly string $label = 'none') {}

    public function name(): string
    {
        return $this->label;
    }

    public function ready(): bool
    {
        return false;
    }

    public function initialize(Payment $payment, Checkout $checkout): string
    {
        throw new PaymentGatewayError('Online payment is not set up.');
    }

    public function verify(Payment $payment): VerifyResult
    {
        throw new PaymentGatewayError('Online payment is not set up.');
    }
}
