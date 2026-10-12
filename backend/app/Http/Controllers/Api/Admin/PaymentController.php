<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Online payment attempts (for support questions) and the refunds the platform has to send.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(Payment::STATUSES)],
            'q' => ['nullable', 'string', 'max:64'],
        ]);
        $rows = Payment::with(['customer:id,name,email', 'checkout.orders:id,checkout_id,number'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(trim($data['q'] ?? ''), fn ($q, $t) => $q->where(fn ($w) => $w->where('tx_ref', $t)->orWhere('provider_reference', $t)))
            ->latest('id')->paginate(25)->withQueryString();

        $gateway = $this->payments->gateway();

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Payment $p) => [
                'id' => $p->id,
                'tx_ref' => $p->tx_ref,
                'provider' => $p->provider,
                'provider_reference' => $p->provider_reference,
                'method' => $p->method,
                'status' => $p->status,
                'problem' => $p->problem,
                'amount_santim' => $p->amount_santim,
                'customer' => ['id' => $p->customer?->id, 'name' => $p->customer?->name, 'email' => $p->customer?->email],
                'orders' => $p->checkout?->orders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number])->values(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'gateway' => $gateway->name(),
                'gateway_ready' => $gateway->ready(),
                'online_available' => $this->payments->available(),
                'refunds_due' => Refund::where('status', 'due')->count(),
                'refunds_due_santim' => (int) Refund::where('status', 'due')->sum('amount_santim'),
            ],
        ]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['due', 'refunded'])]]);
        $rows = Refund::with(['customer:id,name,email,phone', 'order:id,number', 'payment:id,tx_ref,provider_reference,method', 'refunder:id,name'])
            ->where('status', $data['status'] ?? 'due')
            ->orderBy(($data['status'] ?? 'due') === 'due' ? 'id' : 'refunded_at', ($data['status'] ?? 'due') === 'due' ? 'asc' : 'desc')
            ->paginate(25)->withQueryString();

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Refund $r) => self::refund($r)),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function completeRefund(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(Refund::METHODS)],
            'reference' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => $request->input('method') !== 'cash')],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['reference.required' => 'Enter the refund or transfer reference.']);

        $refund = $this->payments->completeRefund(
            $id, $data['method'],
            isset($data['reference']) ? (trim($data['reference']) ?: null) : null,
            isset($data['note']) ? (trim($data['note']) ?: null) : null,
            $request->user(),
        );

        return response()->json(['data' => self::refund($refund->load(['customer', 'order', 'payment', 'refunder']))]);
    }

    private static function refund(Refund $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'amount_santim' => $r->amount_santim,
            'reason' => $r->reason,
            'customer' => ['id' => $r->customer?->id, 'name' => $r->customer?->name, 'email' => $r->customer?->email, 'phone' => $r->customer?->phone],
            'order' => $r->order ? ['id' => $r->order->id, 'number' => $r->order->number] : null,
            'payment' => $r->payment ? ['tx_ref' => $r->payment->tx_ref, 'provider_reference' => $r->payment->provider_reference, 'method' => $r->payment->method] : null,
            'method' => $r->method,
            'reference' => $r->reference,
            'note' => $r->note,
            'refunded_by' => $r->refunder?->name,
            'refunded_at' => $r->refunded_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
