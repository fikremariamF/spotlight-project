<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationType;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inquiries between a customer and a store. The same endpoints serve both sides;
 * the signed-in user's role decides which conversations they can see.
 */
class ConversationController extends Controller
{
    public const MESSAGE_LIMIT = 200;

    public function __construct(private readonly ConversationService $conversations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([Conversation::STATUS_OPEN, Conversation::STATUS_CLOSED])],
            'unread' => ['nullable', 'boolean'],
        ]);
        $side = $this->side($request->user());

        $query = $this->scoped($request->user())
            ->with(['seller', 'customer', 'product.primaryImage', 'latestMessage'])
            ->when($data['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->boolean('unread'), fn (Builder $q) => $q->unreadFor($side))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        return ConversationResource::collection($query->paginate(20)->withQueryString())
            ->additional(['meta' => ['viewer_side' => $side]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => ['unread' => $this->scoped($user)->unreadFor($this->side($user))->count()]]);
    }

    /**
     * Start an inquiry about a listing or a store. A second inquiry about the same listing
     * while the first is still open is appended to it instead of opening a duplicate thread.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_slug' => ['required_without:seller_slug', 'nullable', 'string', 'max:200'],
            'seller_slug' => ['required_without:product_slug', 'nullable', 'string', 'max:200'],
            'type' => ['required', Rule::enum(ConversationType::class)],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);
        $type = ConversationType::from($data['type']);
        $customer = $request->user();

        [$seller, $product] = $this->resolveTarget($data);
        $this->assertTypeAllowed($type, $seller, $product);

        [$conversation, $created] = DB::transaction(function () use ($customer, $seller, $product, $type, $data) {
            // Serialise this customer's inquiries so two quick submits cannot open two threads.
            User::whereKey($customer->id)->lockForUpdate()->first();

            $conversation = Conversation::where('customer_id', $customer->id)
                ->where('seller_profile_id', $seller->id)
                ->where('product_id', $product?->id)
                ->where('status', Conversation::STATUS_OPEN)
                ->first();
            $created = $conversation === null;

            if ($created) {
                $conversation = new Conversation;
                $conversation->customer_id = $customer->id;
                $conversation->seller_profile_id = $seller->id;
                $conversation->product_id = $product?->id;
                $conversation->type = $type;
                $conversation->subject = trim($data['subject'] ?? '') ?: ($product?->name ?? $type->label());
                $conversation->save();
            }

            $this->conversations->post($conversation, $customer, 'customer', trim($data['body']), isNewInquiry: $created);

            return [$conversation, $created];
        });

        return $this->detail($conversation, 'customer')->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, int $id): ConversationResource
    {
        $user = $request->user();
        $conversation = $this->scoped($user)->findOrFail($id);
        $side = $this->side($user);
        $conversation->markReadFor($side);

        return $this->detail($conversation, $side);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message first.']);
        }

        $user = $request->user();
        $side = $this->side($user);

        $message = DB::transaction(function () use ($user, $id, $side, $body) {
            $conversation = $this->scoped($user)->lockForUpdate()->findOrFail($id);
            $this->conversations->assertWritable($conversation);

            return $this->conversations->post($conversation, $user, $side, $body);
        });

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    public function close(Request $request, int $id): ConversationResource
    {
        $user = $request->user();
        $side = $this->side($user);

        $conversation = DB::transaction(function () use ($user, $id, $side) {
            $conversation = $this->scoped($user)->lockForUpdate()->findOrFail($id);
            if ($conversation->isOpen()) {
                $conversation->status = Conversation::STATUS_CLOSED;
                $conversation->save();
                // A closed thread cannot hold a live offer: the closer's side ends any pending quote.
                $conversation->quotes()->where('status', QuoteStatus::Pending->value)->update([
                    'status' => ($side === 'customer' ? QuoteStatus::Declined : QuoteStatus::Withdrawn)->value,
                    'responded_at' => now(),
                ]);
            }

            return $conversation;
        });

        return $this->detail($conversation, $side);
    }

    /**
     * @return array{0: SellerProfile, 1: Product|null}
     */
    private function resolveTarget(array $data): array
    {
        if (! empty($data['product_slug'])) {
            $product = Product::visible()->with('seller')->where('slug', $data['product_slug'])->first();
            if (! $product) {
                throw ValidationException::withMessages(['product_slug' => 'This listing is not available.']);
            }

            return [$product->seller, $product];
        }

        $seller = SellerProfile::publiclyVisible()->where('slug', $data['seller_slug'])->first();
        if (! $seller) {
            throw ValidationException::withMessages(['seller_slug' => 'This store is not available.']);
        }

        return [$seller, null];
    }

    private function assertTypeAllowed(ConversationType $type, SellerProfile $seller, ?Product $product): void
    {
        $error = match (true) {
            $product === null && in_array($type, [ConversationType::Availability, ConversationType::Negotiation], true) => 'Choose a listing to ask about availability or price.',
            $type === ConversationType::Negotiation && ! $product?->price_negotiable => 'The seller has marked this price as fixed.',
            $type === ConversationType::CustomRequest && $product !== null && ! $product->custom_order_available => 'This seller does not take custom orders for this item. Ask a question instead.',
            $type === ConversationType::CustomRequest && $product === null && ! $seller->offers_custom_orders => 'This store does not take custom orders. Ask a question instead.',
            default => null,
        };

        if ($error) {
            throw ValidationException::withMessages(['type' => $error]);
        }
    }

    private function side(User $user): string
    {
        return $user->role === Role::Seller ? 'seller' : 'customer';
    }

    /**
     * Conversations the user takes part in. Anything else is a 404, never a 403, so ids do not leak.
     */
    private function scoped(User $user): Builder
    {
        if ($user->role === Role::Seller) {
            return Conversation::where('seller_profile_id', $user->sellerProfile?->id ?? 0);
        }

        return Conversation::where('customer_id', $user->id);
    }

    private function detail(Conversation $conversation, string $side): ConversationResource
    {
        $conversation->load(['seller', 'customer', 'product.primaryImage', 'quotes']);
        // Show the most recent messages; very long threads are trimmed from the start.
        $conversation->setRelation('messages', $conversation->messages()->reorder()->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)->get()->reverse()->values());

        return ConversationResource::forSide($side, $conversation);
    }
}
