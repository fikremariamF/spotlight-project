<?php

namespace App\Http\Controllers\Api;

use App\Enums\SupportStatus;
use App\Enums\SupportTopic;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportMessageResource;
use App\Http\Resources\SupportTicketResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A customer's inquiries to Betoch support. Every lookup is scoped to the signed-in customer,
 * so another customer's inquiry answers 404, and only rows meant for the customer are returned.
 */
class SupportController extends Controller
{
    public const MESSAGE_LIMIT = 200;

    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'done'])]]);

        $tickets = $this->scoped($request->user())
            ->with(['product.primaryImage', 'order', 'latestPublicMessage'])
            ->when(($data['status'] ?? null) === 'active', fn (Builder $q) => $q->whereIn('status', SupportStatus::activeValues()))
            ->when(($data['status'] ?? null) === 'done', fn (Builder $q) => $q->whereNotIn('status', SupportStatus::activeValues()))
            ->orderByDesc('last_public_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return SupportTicketResource::collection($tickets->through(fn ($t) => (new SupportTicketResource($t))->as('customer')));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->scoped($request->user())->unreadForCustomer()->count()]]);
    }

    /**
     * Ask Betoch. A listing, one of the customer's orders, or a store can be attached; the listing or
     * order comes from the page the customer asked on, never typed in.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', Rule::enum(SupportTopic::class)],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'min:2', 'max:3000'],
            'product_slug' => ['nullable', 'string', 'max:200', 'prohibits:order_id,seller_slug'],
            'order_id' => ['nullable', 'integer', 'prohibits:seller_slug'],
            'seller_slug' => ['nullable', 'string', 'max:200'],
        ]);
        $body = trim($data['body']);
        if (mb_strlen($body) < 2) {
            throw ValidationException::withMessages(['body' => 'Write your question first.']);
        }
        $customer = $request->user();

        $product = null;
        $order = null;
        $store = null;
        if (! empty($data['product_slug'])) {
            $product = Product::visible()->where('slug', $data['product_slug'])->first()
                ?? throw ValidationException::withMessages(['product_slug' => 'This listing is not available.']);
        } elseif (! empty($data['order_id'])) {
            // Someone else's order gets the same answer as a missing one.
            $order = Order::where('customer_id', $customer->id)->find($data['order_id'])
                ?? throw ValidationException::withMessages(['order_id' => 'Choose one of your own orders.']);
        } elseif (! empty($data['seller_slug'])) {
            $store = SellerProfile::publiclyVisible()->where('slug', $data['seller_slug'])->first()
                ?? throw ValidationException::withMessages(['seller_slug' => 'This store is not available.']);
        }

        [$ticket, $created] = $this->support->open($customer, SupportTopic::from($data['topic']),
            trim($data['subject'] ?? '') ?: null, $body, $product, $order, $store);

        return $this->detail($ticket)->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, int $id): SupportTicketResource
    {
        $ticket = $this->scoped($request->user())->findOrFail($id);
        $ticket->markReadByCustomer();

        return $this->detail($ticket);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:3000']]);
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message first.']);
        }
        $user = $request->user();

        $message = DB::transaction(function () use ($user, $id, $body) {
            $ticket = $this->scoped($user)->lockForUpdate()->findOrFail($id);

            return $this->support->customerReply($ticket, $user, $body);
        });

        return (new SupportMessageResource($message))->as('customer')->response()->setStatusCode(201);
    }

    /**
     * The customer no longer needs help. Any offer still waiting for an answer is declined.
     */
    public function close(Request $request, int $id): SupportTicketResource
    {
        $user = $request->user();
        $ticket = DB::transaction(function () use ($user, $id) {
            $ticket = $this->scoped($user)->lockForUpdate()->findOrFail($id);
            $this->support->changeStatus($ticket, $user, SupportStatus::Closed, 'Closed by the customer.');

            return $ticket;
        });

        return $this->detail($ticket);
    }

    private function scoped(User $user): Builder
    {
        return SupportTicket::where('customer_id', $user->id);
    }

    private function detail(SupportTicket $ticket): SupportTicketResource
    {
        $ticket->load(['product.primaryImage', 'order', 'seller', 'quotes.seller']);
        // Only what the customer may see, newest 200, oldest first.
        $ticket->setRelation('messages', $ticket->publicMessages()->reorder()->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)->get()->reverse()->values());

        return (new SupportTicketResource($ticket))->as('customer');
    }
}
