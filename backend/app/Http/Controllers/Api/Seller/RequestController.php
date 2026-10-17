<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerRequestResource;
use App\Models\SellerRequest;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin requests: what Betoch asks the store. The store answers Betoch only; customers never see
 * these messages, and the store never sees the customer's inquiry or contact details.
 */
class RequestController extends Controller
{
    public const MAX_VALID_DAYS = 60;

    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'closed', 'unread'])]]);

        $requests = $this->scoped($request)
            ->with(['product', 'order'])
            ->when(($data['status'] ?? null) === 'active', fn (Builder $q) => $q->where('status', '!=', SellerRequest::STATUS_CLOSED))
            ->when(($data['status'] ?? null) === 'closed', fn (Builder $q) => $q->where('status', SellerRequest::STATUS_CLOSED))
            ->when(($data['status'] ?? null) === 'unread', fn (Builder $q) => $q->unreadForSeller())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return SellerRequestResource::collection($requests->through(fn ($r) => (new SellerRequestResource($r))->as('seller')));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->scoped($request)->unreadForSeller()->count()]]);
    }

    public function show(Request $request, int $id): SellerRequestResource
    {
        $sellerRequest = $this->scoped($request)->findOrFail($id);
        $sellerRequest->markReadBySeller();

        return $this->detail($sellerRequest);
    }

    /**
     * Answer Betoch, optionally with a priced proposal Betoch may send to the customer.
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $money = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:3000'],
            'proposal' => ['nullable', 'array'],
            'proposal.price' => ['required_with:proposal', 'string', $money],
            'proposal.quantity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'proposal.delivery_fee' => ['nullable', 'string', $money],
            'proposal.production_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'proposal.valid_until' => ['required_with:proposal', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(self::MAX_VALID_DAYS)->toDateString()],
        ]);
        $body = trim($data['body']) ?: throw ValidationException::withMessages(['body' => 'Write a message first.']);
        $proposal = ! empty($data['proposal']) ? SupportService::proposalFrom($data['proposal'], Setting::storesSetDeliveryFee()) : [];
        $user = $request->user();

        $sellerRequest = DB::transaction(function () use ($request, $id, $user, $body, $proposal) {
            // Inquiry before request, the same lock order the admin side uses.
            $ticketId = $this->scoped($request)->whereKey($id)->value('support_ticket_id');
            if ($ticketId) {
                SupportTicket::lockForUpdate()->find($ticketId);
            }
            $sellerRequest = $this->scoped($request)->lockForUpdate()->findOrFail($id);
            $this->support->requestMessage($sellerRequest, $user, 'seller', $body, $proposal);

            return $sellerRequest;
        });

        return $this->detail($sellerRequest)->response()->setStatusCode(201);
    }

    private function scoped(Request $request): Builder
    {
        return SellerRequest::where('seller_profile_id', $request->user()->sellerProfile?->id ?? 0);
    }

    private function detail(SellerRequest $sellerRequest): SellerRequestResource
    {
        $sellerRequest->load(['product', 'order', 'messages.quote']);

        return (new SellerRequestResource($sellerRequest))->as('seller');
    }
}
