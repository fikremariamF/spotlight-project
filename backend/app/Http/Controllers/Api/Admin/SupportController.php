<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Enums\SupportStatus;
use App\Enums\SupportTopic;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\SupportMessageResource;
use App\Http\Resources\SupportTicketResource;
use App\Models\Conversation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SupportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The support inbox: every customer inquiry, with notes, history and the store requests made for it.
 * Old customer-store conversations are readable here too, but nobody can add to them.
 */
class SupportController extends Controller
{
    public function __construct(
        private readonly SupportService $support,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'all', ...array_column(SupportStatus::cases(), 'value')])],
            'assigned' => ['nullable', 'string', 'max:20'],
            'topic' => ['nullable', Rule::enum(SupportTopic::class)],
            'escalated' => ['nullable', 'boolean'],
            'needs_reply' => ['nullable', 'boolean'],
            'store' => ['nullable', 'integer'],
        ]);
        $admin = $request->user();
        $status = $data['status'] ?? 'active';

        $tickets = SupportTicket::query()
            ->with(['customer', 'assignee', 'product.primaryImage', 'order', 'seller', 'latestPublicMessage'])
            ->when($status === 'active', fn (Builder $q) => $q->whereIn('status', SupportStatus::activeValues()))
            ->when(! in_array($status, ['active', 'all'], true), fn (Builder $q) => $q->where('status', $status))
            ->when($data['assigned'] ?? null, fn (Builder $q, $a) => match ($a) {
                'me' => $q->where('assigned_to', $admin->id),
                'none' => $q->whereNull('assigned_to'),
                default => ctype_digit($a) ? $q->where('assigned_to', (int) $a) : $q,
            })
            ->when($data['topic'] ?? null, fn (Builder $q, $t) => $q->where('topic', $t))
            ->when($request->boolean('escalated'), fn (Builder $q) => $q->whereNotNull('escalated_at'))
            ->when($request->boolean('needs_reply'), fn (Builder $q) => $q->needsReply())
            ->when($data['store'] ?? null, fn (Builder $q, $s) => $q->where('seller_profile_id', $s))
            ->when(trim($data['q'] ?? ''), fn (Builder $q, $term) => $this->search($q, $term))
            ->orderByRaw('escalated_at is null')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $active = SupportTicket::whereIn('status', SupportStatus::activeValues());

        return SupportTicketResource::collection($tickets->through(fn ($t) => (new SupportTicketResource($t))->as('admin')))
            ->additional(['meta' => ['counts' => [
                'new' => SupportTicket::where('status', SupportStatus::New->value)->count(),
                'needs_reply' => SupportTicket::needsReply()->count(),
                'mine' => (clone $active)->where('assigned_to', $admin->id)->count(),
                'unassigned' => (clone $active)->whereNull('assigned_to')->count(),
                'escalated' => (clone $active)->whereNotNull('escalated_at')->count(),
            ]]]);
    }

    public function show(int $id): SupportTicketResource
    {
        return $this->detail(SupportTicket::findOrFail($id));
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            // What the inquiry becomes after this reply (optional).
            'status' => ['nullable', Rule::in([SupportStatus::Open->value, SupportStatus::Waiting->value, SupportStatus::Resolved->value, SupportStatus::Closed->value])],
        ]);
        $body = $this->text($data['body']);
        $admin = $request->user();

        $message = DB::transaction(function () use ($id, $admin, $body, $data) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

            return $this->support->adminReply($ticket, $admin, $body, isset($data['status']) ? SupportStatus::from($data['status']) : null);
        });

        return (new SupportMessageResource($message))->as('admin')->response()->setStatusCode(201);
    }

    /**
     * A private note: never shown to the customer or the store.
     */
    public function note(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $body = $this->text($data['body']);
        $admin = $request->user();

        $message = DB::transaction(function () use ($id, $admin, $body) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

            return $this->support->note($ticket, $admin, $body);
        });

        return (new SupportMessageResource($message))->as('admin')->response()->setStatusCode(201);
    }

    /**
     * Status, assignment and escalation. Each change is written to the inquiry's history and the audit log.
     */
    public function update(Request $request, int $id): SupportTicketResource
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(SupportStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'escalated' => ['sometimes', 'boolean'],
            'escalation_reason' => ['nullable', 'string', 'max:300'],
        ]);
        $admin = $request->user();

        $assignee = null;
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $assignee = User::where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->find($data['assigned_to'])
                ?? throw ValidationException::withMessages(['assigned_to' => 'Choose an active admin.']);
        }
        if (($data['escalated'] ?? false) && trim($data['escalation_reason'] ?? '') === '') {
            throw ValidationException::withMessages(['escalation_reason' => 'Say why it is escalated, for whoever picks it up.']);
        }

        $ticket = DB::transaction(function () use ($id, $admin, $data, $assignee) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            if (array_key_exists('assigned_to', $data)) {
                $this->support->assign($ticket, $admin, $assignee);
            }
            if (array_key_exists('escalated', $data)) {
                $this->support->escalate($ticket, $admin, (bool) $data['escalated'], trim($data['escalation_reason'] ?? '') ?: null);
            }
            if (isset($data['status'])) {
                $this->support->changeStatus($ticket, $admin, SupportStatus::from($data['status']), trim($data['note'] ?? '') ?: null);
            }

            return $ticket;
        });

        return $this->detail($ticket);
    }

    /**
     * Admins an inquiry can be assigned to.
     */
    public function admins(): JsonResponse
    {
        return response()->json(['data' => User::where('role', Role::Admin->value)->where('status', UserStatus::Active->value)
            ->orderBy('name')->get(['id', 'name'])]);
    }

    /**
     * Customer-store conversations from before support messaging, read-only.
     */
    public function legacyIndex(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($data['q'] ?? '');
        $conversations = Conversation::with(['seller', 'customer', 'product.primaryImage', 'latestMessage'])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('subject', 'like', $this->like($term))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $this->like($term))->orWhere('email', 'like', $this->like($term)))
                ->orWhereHas('seller', fn (Builder $s) => $s->where('store_name', 'like', $this->like($term)))))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return ConversationResource::collection($conversations->through(fn ($c) => ConversationResource::forSide('admin', $c)));
    }

    public function legacyShow(int $id): ConversationResource
    {
        $conversation = Conversation::with(['seller', 'customer', 'product.primaryImage', 'quotes', 'messages'])->findOrFail($id);
        $this->audit->log('support.legacy_viewed', $conversation);

        return ConversationResource::forSide('admin', $conversation);
    }

    private function detail(SupportTicket $ticket): SupportTicketResource
    {
        $ticket->load([
            'customer', 'assignee', 'product.primaryImage', 'order', 'seller', 'quotes.seller', 'messages.author',
            'sellerRequests' => fn ($q) => $q->with(['seller', 'creator', 'product', 'order', 'messages.author', 'messages.quote']),
        ]);

        return (new SupportTicketResource($ticket))->as('admin');
    }

    private function search(Builder $query, string $term): void
    {
        $id = ltrim($term, '#');
        $query->where(function (Builder $q) use ($term, $id) {
            if (ctype_digit($id)) {
                $q->where('id', (int) $id);
            }
            $q->orWhere('subject', 'like', $this->like($term))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $this->like($term))->orWhere('email', 'like', $this->like($term)))
                ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', $this->like($term)))
                ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', $this->like($term)))
                ->orWhereHas('seller', fn (Builder $s) => $s->where('store_name', 'like', $this->like($term)));
        });
    }

    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    private function text(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message first.']);
        }

        return $body;
    }
}
