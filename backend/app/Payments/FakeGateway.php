<?php

namespace App\Payments;

use App\Models\Checkout;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;

/**
 * For local tries and tests only: sends the customer to a page in this app where they choose
 * "pay" or "fail". It is never bound when APP_ENV=production (see AppServiceProvider), so it cannot
 * mark a real order paid.
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function ready(): bool
    {
        return true;
    }

    public function initialize(Payment $payment, Checkout $checkout): string
    {
        return rtrim((string) config('app.url'), '/').'/pay/test?tx_ref='.urlencode($payment->tx_ref);
    }

    public function verify(Payment $payment): VerifyResult
    {
        return match (self::outcome($payment->tx_ref)) {
            'paid' => new VerifyResult('paid', $payment->amount_santim, $payment->currency, 'TEST-'.strtoupper(substr(md5($payment->tx_ref), 0, 10)), 'test'),
            'failed' => new VerifyResult('failed'),
            default => VerifyResult::pending(),
        };
    }

    /** @param  'paid'|'failed'  $outcome */
    public static function choose(string $txRef, string $outcome): void
    {
        Cache::put('fake-payment:'.$txRef, $outcome, now()->addDay());
    }

    public static function outcome(string $txRef): ?string
    {
        return Cache::get('fake-payment:'.$txRef);
    }
}
