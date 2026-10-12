<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Sms\SmsGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Every SMS the platform tried to send, newest first, for checking delivery problems and cost.
 */
class SmsLogController extends Controller
{
    public function index(Request $request, SmsGateway $gateway): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SmsMessage::STATUSES)],
            'q' => ['nullable', 'string', 'max:40'],
        ]);
        $phone = isset($filters['q']) ? PhoneNumber::ethiopianMobile($filters['q']) : null;

        $page = SmsMessage::with('user:id,name,email')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) use ($phone) {
                $q->where(function ($q) use ($term, $phone) {
                    $q->where('to', $phone ?? $term)
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.addcslashes($term, '%_\\').'%'));
                });
            })
            ->orderByDesc('id')
            ->paginate(30);

        $since = now()->subDays(30);
        $counts = SmsMessage::where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => collect($page->items())->map(fn (SmsMessage $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'to' => $m->to,
                'body' => $m->body,
                'status' => $m->status,
                'driver' => $m->driver,
                'error' => $m->error,
                'attempts' => $m->attempts,
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email] : null,
                'created_at' => $m->created_at?->toIso8601String(),
                'sent_at' => $m->sent_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'last_30_days' => collect(SmsMessage::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
                'driver' => $gateway->name(),
                'delivers' => $gateway->delivers(),
                'sms_enabled' => Setting::smsEnabled(),
            ],
        ]);
    }
}
