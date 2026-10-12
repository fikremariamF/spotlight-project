<?php

namespace App\Http\Controllers\Api\Account;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a user's alerts go: their phone number and whether SMS and email are on.
 */
class AlertSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'alert_sms' => ['sometimes', 'boolean'],
            'alert_email' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('phone', $data)) {
            $data['phone'] = filled($data['phone']) ? trim($data['phone']) : null;
        }

        $user = $request->user();
        $user->fill($data);
        $changed = array_keys($user->getDirty());
        $user->save();
        if ($changed) {
            $audit->log('account.alerts_updated', $user, ['fields' => $changed]);
        }

        return response()->json(['data' => $this->present($user)]);
    }

    private function present(User $user): array
    {
        $isSeller = $user->role === Role::Seller;
        $storePhone = $isSeller ? $user->loadMissing('sellerProfile')->sellerProfile?->phone : null;
        $smsTo = $user->routeNotificationForSms();

        return [
            'phone' => $user->phone,
            'alert_sms' => $user->alert_sms,
            'alert_email' => $user->alert_email,
            'email' => $user->email,
            // Which number SMS alerts go to, and why, so the page can explain it.
            'sms_to' => $smsTo,
            'sms_to_source' => $smsTo === null ? null : ($isSeller && PhoneNumber::ethiopianMobile($storePhone) ? 'store' : 'account'),
            'store_phone' => $storePhone,
            'sms_paused' => ! Setting::smsEnabled(),
        ];
    }
}
