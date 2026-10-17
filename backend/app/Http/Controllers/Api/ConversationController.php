<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Customer-store conversations from before support messaging. They are kept as read-only history:
 * the customer can read theirs, stores no longer can, and nobody can write in them.
 * New questions go to Betoch support (SupportController).
 */
class ConversationController extends Controller
{
    public const MESSAGE_LIMIT = 200;

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

    public function show(Request $request, int $id): ConversationResource
    {
        $user = $request->user();
        $conversation = $this->scoped($user)->findOrFail($id);
        $side = $this->side($user);
        $conversation->markReadFor($side);

        return $this->detail($conversation, $side);
    }

    private function side(User $user): string
    {
        return $user->role === Role::Seller ? 'seller' : 'customer';
    }

    /**
     * Only customers reach these routes; the seller branch stays as a second guard.
     * Anything else is a 404, never a 403, so ids do not leak.
     */
    private function scoped(User $user): Builder
    {
        if ($user->role === Role::Seller) {
            return Conversation::whereRaw('1 = 0');
        }

        return Conversation::where('customer_id', $user->id);
    }

    private function detail(Conversation $conversation, string $side): ConversationResource
    {
        $conversation->load(['seller', 'customer', 'product.primaryImage', 'quotes.seller']);
        // Show the most recent messages; very long threads are trimmed from the start.
        $conversation->setRelation('messages', $conversation->messages()->reorder()->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)->get()->reverse()->values());

        return ConversationResource::forSide($side, $conversation);
    }
}
