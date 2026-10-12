<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\PaymentGatewayError;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer side of online payment, plus the provider's callback and webhook.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Get (or reuse) a payment page for an unpaid online checkout. */
    public function pay(Request $request, int $id): JsonResponse
    {
        $checkout = $this->own($request, $id);
        $payment = $this->payments->start($checkout);

        return response()->json(['data' => self::state($checkout->fresh(), $payment)]);
    }

    /** Where the customer lands after the provider's page: checks with the provider and reports. */
    public function status(Request $request, int $id): JsonResponse
    {
        $checkout = $this->own($request, $id);
        $latest = Payment::where('checkout_id', $checkout->id)->latest('id')->first();
        $checkError = false;
        if ($latest && $latest->status === 'pending' && $checkout->paid_at === null) {
            try {
                $latest = $this->payments->refresh($latest);
            } catch (PaymentGatewayError $e) {
                report($e);
                $checkError = true;
            }
        }

        return response()->json(['data' => [...self::state($checkout->fresh(), $latest?->fresh()), 'check_failed' => $checkError]]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $checkout = $this->own($request, $id);
        $reason = isset($data['reason']) && trim($data['reason']) !== '' ? trim($data['reason']) : 'Cancelled before payment';
        $this->payments->cancelUnpaid($checkout, 'customer', $request->user(), $reason);

        return response()->json(['data' => self::state($checkout->fresh(), null)]);
    }

    /**
     * Test gateway only: the customer chooses what "happened" at the provider. The route exists only
     * when the fake gateway is active, and the fake gateway is never active in production.
     */
    public function testOutcome(Request $request, string $txRef): JsonResponse
    {
        abort_unless($this->payments->gateway() instanceof FakeGateway, 404);
        $data = $request->validate(['outcome' => ['required', Rule::in(['paid', 'failed'])]]);
        $payment = Payment::where('tx_ref', $txRef)->where('customer_id', $request->user()->id)->firstOrFail();
        FakeGateway::choose($payment->tx_ref, $data['outcome']);
        $payment = $this->payments->refresh($payment, force: true);

        return response()->json(['data' => ['checkout_id' => $payment->checkout_id, 'status' => $payment->status]]);
    }

    /** Test gateway only: what the test page shows. */
    public function testShow(Request $request, string $txRef): JsonResponse
    {
        abort_unless($this->payments->gateway() instanceof FakeGateway, 404);
        $payment = Payment::where('tx_ref', $txRef)->where('customer_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => [
            'tx_ref' => $payment->tx_ref,
            'amount_santim' => $payment->amount_santim,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'checkout_id' => $payment->checkout_id,
        ]]);
    }

    /**
     * Chapa redirects its server here (GET, with trx_ref) when a payment ends. It only prompts a
     * verify, so a forged call cannot mark anything paid.
     */
    public function chapaCallback(Request $request): JsonResponse
    {
        $txRef = (string) ($request->query('trx_ref') ?? $request->query('tx_ref') ?? $request->input('trx_ref') ?? $request->input('tx_ref') ?? '');

        return $this->verifyByReference($txRef);
    }

    /** Chapa webhook. Unsigned or badly signed calls are refused; signed ones still only prompt a verify. */
    public function chapaWebhook(Request $request): JsonResponse
    {
        $gateway = $this->payments->gateway();
        if (! $gateway instanceof ChapaGateway) {
            return response()->json(['message' => 'Not accepting Chapa webhooks.'], 404);
        }
        if (! $gateway->webhookSignatureValid($request->getContent(), $request->header('x-chapa-signature'), $request->header('chapa-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $this->verifyByReference((string) ($request->input('tx_ref') ?? $request->input('trx_ref') ?? ''));
    }

    private function verifyByReference(string $txRef): JsonResponse
    {
        $payment = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $txRef) ? Payment::where('tx_ref', $txRef)->first() : null;
        if (! $payment) {
            // Nothing to say to an unknown caller; 200 stops the provider from retrying forever.
            return response()->json(['received' => true]);
        }
        try {
            $this->payments->refresh($payment, force: true);
        } catch (PaymentGatewayError $e) {
            report($e);

            // Ask the provider to try again later.
            return response()->json(['received' => false], 503);
        }

        return response()->json(['received' => true]);
    }

    private function own(Request $request, int $id): Checkout
    {
        return Checkout::where('customer_id', $request->user()->id)->findOrFail($id);
    }

    /**
     * The checkout's payment as the customer sees it.
     */
    public static function state(Checkout $checkout, ?Payment $payment): array
    {
        $orders = Order::where('checkout_id', $checkout->id)->with('seller')->orderBy('id')->get();
        $awaiting = $orders->contains(fn (Order $o) => $o->status === OrderStatus::AwaitingPayment);
        $status = match (true) {
            ! $checkout->isOnline() => 'cash',
            $checkout->paid_at !== null => 'paid',
            $awaiting && $checkout->payment_due_at?->isPast() => 'expired',
            $awaiting => 'awaiting',
            default => 'cancelled',
        };

        return [
            'checkout_id' => $checkout->id,
            'status' => $status,
            'grand_total_santim' => $checkout->grand_total_santim,
            'payment_due_at' => $checkout->payment_due_at?->toIso8601String(),
            'paid_at' => $checkout->paid_at?->toIso8601String(),
            'can_pay' => $status === 'awaiting',
            'payment' => $payment ? [
                'tx_ref' => $payment->tx_ref,
                'status' => $payment->status,
                'checkout_url' => $status === 'awaiting' && $payment->status === 'pending' ? $payment->checkout_url : null,
                'method' => $payment->method,
            ] : null,
            'orders' => $orders->map(fn (Order $o) => (new OrderResource($o))->as('customer')->resolve())->values(),
        ];
    }
}
