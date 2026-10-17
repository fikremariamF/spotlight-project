<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\QuoteStatus;
use App\Enums\SellerRequestTopic;
use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\SellerRequestResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\SellerRequest;
use App\Models\SellerRequestMessage;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admins asking stores: stock checks, product details, prices, delivery and fulfilment.
 * A store's answer stays here until an admin writes to the customer or sends its proposal as an offer.
 */
class SellerRequestController extends Controller
{
    public const MAX_OFFER_DAYS = 60;

    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'all', SellerRequest::STATUS_OPEN, SellerRequest::STATUS_ANSWERED, SellerRequest::STATUS_CLOSED])],
            'store' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $data['status'] ?? 'active';
        $term = trim($data['q'] ?? '');

        $requests = SellerRequest::with(['seller', 'ticket', 'product', 'order', 'creator'])
            ->when($status === 'active', fn (Builder $q) => $q->where('status', '!=', SellerRequest::STATUS_CLOSED))
            ->when(! in_array($status, ['active', 'all'], true), fn (Builder $q) => $q->where('status', $status))
            ->when($data['store'] ?? null, fn (Builder $q, $s) => $q->where('seller_profile_id', $s))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('subject', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('seller', fn (Builder $s) => $s->where('store_name', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            // Answers waiting for Betoch first.
            ->orderByRaw("case when status = 'answered' then 0 when status = 'open' then 1 else 2 end")
            ->orderByDesc('last_message_at')
            ->paginate(25)
            ->withQueryString();

        return SellerRequestResource::collection($requests->through(fn ($r) => (new SellerRequestResource($r))->as('admin')))
            ->additional(['meta' => ['counts' => [
                'answered' => SellerRequest::where('status', SellerRequest::STATUS_ANSWERED)->count(),
                'open' => SellerRequest::where('status', SellerRequest::STATUS_OPEN)->count(),
            ]]]);
    }

    public function show(int $id): SellerRequestResource
    {
        return $this->detail(SellerRequest::findOrFail($id));
    }

    /**
     * Ask a store. From an inquiry, the store defaults to the inquiry's; the listing and order must be the store's.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'support_ticket_id' => ['nullable', 'integer'],
            'seller_profile_id' => ['required', 'integer'],
            'topic' => ['required', Rule::enum(SellerRequestTopic::class)],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:2', 'max:3000'],
            'product_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
        ]);
        $store = SellerProfile::whereIn('status', [SellerStatus::Approved->value, SellerStatus::Suspended->value])->find($data['seller_profile_id'])
            ?? throw ValidationException::withMessages(['seller_profile_id' => 'Choose an approved store.']);
        $product = isset($data['product_id']) ? (Product::withTrashed()->find($data['product_id'])
            ?? throw ValidationException::withMessages(['product_id' => 'This listing does not exist.'])) : null;
        $order = isset($data['order_id']) ? (Order::find($data['order_id'])
            ?? throw ValidationException::withMessages(['order_id' => 'This order does not exist.'])) : null;
        $admin = $request->user();

        $sellerRequest = DB::transaction(function () use ($data, $store, $product, $order, $admin) {
            $ticket = null;
            if (isset($data['support_ticket_id'])) {
                $ticket = SupportTicket::lockForUpdate()->find($data['support_ticket_id'])
                    ?? throw ValidationException::withMessages(['support_ticket_id' => 'This inquiry does not exist.']);
            }

            return $this->support->ask($admin, $store, $ticket, SellerRequestTopic::from($data['topic']),
                trim($data['subject']), trim($data['body']), $product, $order);
        });

        return $this->detail($sellerRequest)->response()->setStatusCode(201);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:3000']]);
        $body = trim($data['body']) ?: throw ValidationException::withMessages(['body' => 'Write a message first.']);
        $admin = $request->user();

        $sellerRequest = DB::transaction(function () use ($id, $admin, $body) {
            $sellerRequest = SellerRequest::lockForUpdate()->findOrFail($id);
            $this->support->requestMessage($sellerRequest, $admin, 'admin', $body);

            return $sellerRequest;
        });

        return $this->detail($sellerRequest)->response()->setStatusCode(201);
    }

    public function close(Request $request, int $id): SellerRequestResource
    {
        $sellerRequest = DB::transaction(function () use ($request, $id) {
            $sellerRequest = SellerRequest::lockForUpdate()->findOrFail($id);
            $this->support->closeRequest($sellerRequest, $request->user());

            return $sellerRequest;
        });

        return $this->detail($sellerRequest);
    }

    /**
     * Send a store's proposal to the customer as a Betoch offer.
     */
    public function relay(Request $request, int $id, int $messageId): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(self::MAX_OFFER_DAYS)->toDateString()],
        ]);
        $admin = $request->user();

        $quote = DB::transaction(function () use ($id, $messageId, $data, $admin) {
            // Inquiry before request before quote: the same order as accepting an offer, so no deadlocks.
            $ticketId = SellerRequest::whereKey($id)->value('support_ticket_id');
            if ($ticketId) {
                SupportTicket::lockForUpdate()->find($ticketId);
            }
            $sellerRequest = SellerRequest::lockForUpdate()->findOrFail($id);
            $proposal = SellerRequestMessage::where('seller_request_id', $sellerRequest->id)->lockForUpdate()->findOrFail($messageId);

            return $this->support->relay($sellerRequest, $proposal, $admin, trim($data['title']), trim($data['description']),
                CarbonImmutable::createFromFormat('Y-m-d', $data['valid_until'])->startOfDay());
        });

        return (new QuoteResource($quote->load('seller')))->response()->setStatusCode(201);
    }

    /**
     * Take back an offer the customer has not answered yet.
     */
    public function withdrawOffer(Request $request, int $quoteId): QuoteResource
    {
        $admin = $request->user();
        $quote = DB::transaction(function () use ($quoteId, $admin) {
            $ticketId = Quote::whereNotNull('support_ticket_id')->whereKey($quoteId)->value('support_ticket_id') ?? abort(404);
            SupportTicket::lockForUpdate()->findOrFail($ticketId);
            $quote = Quote::lockForUpdate()->findOrFail($quoteId);
            if ($quote->effectiveStatus() !== QuoteStatus::Pending) {
                throw ValidationException::withMessages(['quote' => 'This offer is already '.$quote->effectiveStatus()->value.'.']);
            }
            $quote->status = QuoteStatus::Withdrawn;
            $quote->responded_at = now();
            $quote->save();
            $this->support->offerAnswered($quote, $admin, QuoteStatus::Withdrawn);

            return $quote;
        });

        return new QuoteResource($quote->load('seller'));
    }

    private function detail(SellerRequest $sellerRequest): SellerRequestResource
    {
        $sellerRequest->load(['seller', 'ticket', 'product', 'order', 'creator', 'messages.author', 'messages.quote']);

        return (new SellerRequestResource($sellerRequest))->as('admin');
    }
}
