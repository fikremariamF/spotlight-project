<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayError;
use App\Payments\VerifyResult;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Online payment for a checkout: start a hosted payment, check it with the provider, and turn the
 * answer into order changes. Only the provider's verify answer marks anything paid.
 *
 * Lock order everywhere: checkout row, then payment row, then order rows.
 */
class PaymentService
{
    /** A recent unpaid attempt is reused instead of starting another one at the provider. */
    private const REUSE_MINUTES = 15;

    /** Customers' status checks reach the provider at most this often per payment. */
    private const CHECK_EVERY_SECONDS = 5;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
    ) {}

    public function available(): bool
    {
        return Setting::onlinePaymentsEnabled() && $this->gateway->ready();
    }

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    /**
     * Get a payment page for an unpaid online checkout, reusing a recent attempt when there is one.
     */
    public function start(Checkout $checkout): Payment
    {
        if (! $this->gateway->ready()) {
            throw ValidationException::withMessages(['payment' => 'Online payment is not available right now.']);
        }

        $payment = DB::transaction(function () use ($checkout) {
            $checkout = Checkout::lockForUpdate()->findOrFail($checkout->id);
            $this->assertPayable($checkout);

            $recent = Payment::where('checkout_id', $checkout->id)->where('status', 'pending')
                ->whereNotNull('checkout_url')->where('amount_santim', $checkout->grand_total_santim)
                ->where('provider', $this->gateway->name())
                ->where('created_at', '>=', now()->subMinutes(self::REUSE_MINUTES))
                ->latest('id')->first();
            if ($recent) {
                return $recent;
            }

            $payment = new Payment;
            $payment->checkout_id = $checkout->id;
            $payment->customer_id = $checkout->customer_id;
            $payment->provider = $this->gateway->name();
            $payment->tx_ref = self::newReference($checkout);
            $payment->amount_santim = $checkout->grand_total_santim;
            $payment->currency = (string) config('marketplace.currency');
            $payment->save();

            return $payment;
        });

        if ($payment->checkout_url) {
            return $payment;
        }

        // The provider call happens outside the transaction, so a slow provider never holds row locks.
        try {
            $url = $this->gateway->initialize($payment, $checkout->fresh(['customer']));
        } catch (PaymentGatewayError $e) {
            report($e);
            $payment->status = 'failed';
            $payment->problem = mb_substr('Could not start: '.$e->getMessage(), 0, 255);
            $payment->save();
            throw new HttpResponseException(response()->json([
                'message' => 'We could not open the payment page. Try again in a minute, or cancel and pay cash on delivery.',
            ], 502));
        }

        $payment->checkout_url = $url;
        $payment->save();
        $this->audit->log('payment.started', $payment, ['checkout_id' => $checkout->id, 'amount_santim' => $payment->amount_santim]);

        return $payment;
    }

    /**
     * Ask the provider about a payment and apply the answer. $force skips the per-payment rate limit
     * (provider callbacks, the expiry job); customers' status checks do not.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if ($payment->status === 'paid') {
            return $payment;
        }
        if (! $force && $payment->last_checked_at && $payment->last_checked_at->gt(now()->subSeconds(self::CHECK_EVERY_SECONDS))) {
            return $payment;
        }
        if ($payment->provider !== $this->gateway->name()) {
            // Made with another gateway (the setting changed); it can no longer be checked from here.
            return $payment;
        }

        $result = $this->gateway->verify($payment);

        return $this->apply($payment->id, $result);
    }

    /**
     * Turn a provider answer into payment and order changes. Safe to run any number of times.
     */
    public function apply(int $paymentId, VerifyResult $result): Payment
    {
        $refund = null;
        $paidNow = false;

        $payment = DB::transaction(function () use ($paymentId, $result, &$refund, &$paidNow) {
            $checkoutId = Payment::whereKey($paymentId)->value('checkout_id');
            $checkout = Checkout::lockForUpdate()->findOrFail($checkoutId);
            $payment = Payment::lockForUpdate()->findOrFail($paymentId);
            $payment->last_checked_at = now();

            if ($payment->status === 'paid' || $result->status === 'pending') {
                $payment->save();

                return $payment;
            }

            if ($result->status === 'failed') {
                if ($payment->status === 'pending') {
                    $payment->status = 'failed';
                    $this->audit->log('payment.failed', $payment, ['tx_ref' => $payment->tx_ref]);
                }
                $payment->save();

                return $payment;
            }

            // The provider says the money was taken. Record it whatever happened on our side since.
            $wasStatus = $payment->status;
            $payment->status = 'paid';
            $payment->paid_at = now();
            $payment->provider_reference = $result->reference;
            $payment->method = $result->method;

            // Less than the total (or another currency) does not pay for the orders and is sent back.
            // More is accepted but noted: some provider accounts add their fee to what the customer pays.
            $expectedCurrency = (string) config('marketplace.currency');
            $reported = 'Provider reported '.($result->currency ?? '?').' '.($result->amountSantim === null ? '?' : number_format($result->amountSantim / 100, 2)).', expected '.$expectedCurrency.' '.number_format($payment->amount_santim / 100, 2);
            if ($result->amountSantim === null || $result->amountSantim < $payment->amount_santim || ($result->currency !== null && $result->currency !== $expectedCurrency)) {
                $payment->problem = mb_substr($reported, 0, 255);
                $payment->save();
                $refund = $this->refundPayment($payment, $result->amountSantim ?? $payment->amount_santim, 'The amount paid did not match the order total');
                $this->audit->log('payment.mismatch', $payment, ['tx_ref' => $payment->tx_ref, 'reported' => $reported]);

                return $payment;
            }
            if ($result->amountSantim > $payment->amount_santim) {
                $payment->problem = mb_substr($reported, 0, 255);
            }

            $payment->save();
            $awaiting = Order::where('checkout_id', $checkout->id)->where('status', OrderStatus::AwaitingPayment->value)->exists();
            if ($checkout->paid_at === null && $awaiting) {
                $this->orders->markPaid($checkout, $payment);
                // Other open attempts for this checkout are dropped; if one is paid anyway, it is refunded.
                Payment::where('checkout_id', $checkout->id)->where('id', '!=', $payment->id)->where('status', 'pending')->update(['status' => 'cancelled']);
                $paidNow = true;
            } else {
                $reason = $checkout->paid_at !== null ? 'This order was already paid by another payment' : 'Paid after the order was cancelled';
                $refund = $this->refundPayment($payment, $payment->amount_santim, $reason);
            }
            $this->audit->log('payment.paid', $payment, ['tx_ref' => $payment->tx_ref, 'was' => $wasStatus, 'matched' => $paidNow]);

            return $payment;
        });

        if ($refund) {
            $this->alerts->refundDue($refund);
        }

        return $payment;
    }

    /**
     * The customer gives up on paying. A payment that went through after all wins: the orders then
     * go ahead as paid (and can be cancelled normally, with a refund).
     */
    public function cancelUnpaid(Checkout $checkout, string $role, ?User $actor, string $reason): void
    {
        $this->settlePending($checkout, failOnError: false);

        DB::transaction(function () use ($checkout, $role, $actor, $reason) {
            $checkout = Checkout::lockForUpdate()->findOrFail($checkout->id);
            if ($checkout->paid_at !== null) {
                throw ValidationException::withMessages(['status' => 'This order was paid, so it went to the store. Cancel it from the order page to get a refund.']);
            }
            $count = $this->orders->cancelUnpaid($checkout, $role, $actor, $reason, restoreCart: $role === 'customer');
            if ($count === 0) {
                throw ValidationException::withMessages(['status' => 'This order is no longer waiting for payment.']);
            }
            Payment::where('checkout_id', $checkout->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            $this->alerts->checkoutCancelledUnpaid($checkout, $role);
        });
    }

    /**
     * Cancel online checkouts whose payment time ran out. A provider that cannot be reached gets one
     * more window before the stock is released anyway (a payment that arrives later is refunded).
     *
     * @return array{expired: int, paid: int, skipped: int}
     */
    public function expireDue(): array
    {
        $counts = ['expired' => 0, 'paid' => 0, 'skipped' => 0];
        $window = max(5, (int) config('marketplace.payments.window_minutes'));

        Checkout::where('payment_method', 'online')->whereNull('paid_at')->where('payment_due_at', '<', now())
            ->whereHas('orders', fn ($q) => $q->where('status', OrderStatus::AwaitingPayment->value))
            ->orderBy('id')->limit(200)->get()
            ->each(function (Checkout $checkout) use (&$counts, $window) {
                $reachable = $this->settlePending($checkout, failOnError: false);
                $checkout->refresh();
                if ($checkout->paid_at !== null) {
                    $counts['paid']++;

                    return;
                }
                if (! $reachable && $checkout->payment_due_at->gt(now()->subMinutes($window))) {
                    $counts['skipped']++;

                    return;
                }

                DB::transaction(function () use ($checkout, &$counts) {
                    $locked = Checkout::lockForUpdate()->findOrFail($checkout->id);
                    if ($locked->paid_at !== null) {
                        return;
                    }
                    if ($this->orders->cancelUnpaid($locked, 'system', null, 'Payment was not completed in time.', restoreCart: true) > 0) {
                        Payment::where('checkout_id', $locked->id)->where('status', 'pending')->update(['status' => 'expired']);
                        $this->alerts->checkoutCancelledUnpaid($locked, 'system');
                        $counts['expired']++;
                    }
                });
            });

        return $counts;
    }

    /**
     * Check every pending attempt of a checkout with the provider. Returns false if any check failed.
     */
    public function settlePending(Checkout $checkout, bool $failOnError = true): bool
    {
        $ok = true;
        foreach (Payment::where('checkout_id', $checkout->id)->where('status', 'pending')->orderBy('id')->get() as $payment) {
            try {
                $this->refresh($payment, force: true);
            } catch (PaymentGatewayError $e) {
                report($e);
                $ok = false;
                if ($failOnError) {
                    throw $e;
                }
            }
        }

        return $ok;
    }

    /**
     * An admin sent the money back (in the provider's dashboard, or by transfer).
     */
    public function completeRefund(int $refundId, string $method, ?string $reference, ?string $note, User $admin): Refund
    {
        $refund = DB::transaction(function () use ($refundId, $method, $reference, $note, $admin) {
            $refund = Refund::lockForUpdate()->findOrFail($refundId);
            if ($refund->status !== 'due') {
                throw ValidationException::withMessages(['status' => 'This refund is already marked as refunded.']);
            }
            $refund->status = 'refunded';
            $refund->method = $method;
            $refund->reference = $reference;
            $refund->note = $note;
            $refund->refunded_by = $admin->id;
            $refund->refunded_at = now();
            $refund->save();

            if ($refund->order_id) {
                Order::whereKey($refund->order_id)->where('payment_status', 'refund_due')->update(['payment_status' => 'refunded']);
            }
            $this->audit->log('refund.completed', $refund, ['amount_santim' => $refund->amount_santim, 'method' => $method, 'reference' => $reference]);

            return $refund;
        });
        $this->alerts->refundCompleted($refund);

        return $refund;
    }

    private function refundPayment(Payment $payment, int $amountSantim, string $reason): ?Refund
    {
        // Runs under the payment's row lock, so a payment is never refunded twice.
        if (Refund::where('payment_id', $payment->id)->whereNull('order_id')->exists()) {
            return null;
        }
        $refund = new Refund;
        $refund->payment_id = $payment->id;
        $refund->customer_id = $payment->customer_id;
        $refund->amount_santim = $amountSantim;
        $refund->reason = $reason;
        $refund->save();
        $this->audit->log('refund.due', $refund, ['payment' => $payment->tx_ref, 'amount_santim' => $amountSantim, 'reason' => $reason]);

        return $refund;
    }

    private function assertPayable(Checkout $checkout): void
    {
        if (! $checkout->isOnline()) {
            throw ValidationException::withMessages(['payment' => 'This order is paid in cash.']);
        }
        if ($checkout->paid_at !== null) {
            throw ValidationException::withMessages(['payment' => 'This order is already paid.']);
        }
        if (! Order::where('checkout_id', $checkout->id)->where('status', OrderStatus::AwaitingPayment->value)->exists()) {
            throw ValidationException::withMessages(['payment' => 'This order is no longer waiting for payment.']);
        }
        if ($checkout->payment_due_at && $checkout->payment_due_at->isPast()) {
            throw ValidationException::withMessages(['payment' => 'The time to pay for this order has run out.']);
        }
    }

    /** Letters, digits and hyphens only, unique, and not guessable from the checkout id alone. */
    private static function newReference(Checkout $checkout): string
    {
        return 'BF-'.$checkout->id.'-'.Str::upper(Str::random(12));
    }
}
