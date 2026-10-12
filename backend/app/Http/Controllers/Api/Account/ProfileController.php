<?php

namespace App\Http\Controllers\Api\Account;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in user's own account: name, email, phone, password and signed-in devices.
 * Changing the email or the password needs the current password, so a token left open on a
 * shared computer cannot be used to take the account over.
 */
class ProfileController extends Controller
{
    /** Wrong current passwords allowed per account every 15 minutes. */
    private const PASSWORD_GUESSES = 5;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request)]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'current_password' => ['nullable', 'string'],
        ]);
        if (array_key_exists('phone', $data)) {
            $data['phone'] = filled($data['phone']) ? trim($data['phone']) : null;
        }

        $oldEmail = $user->email;
        $emailChanges = isset($data['email']) && $data['email'] !== $oldEmail;
        if ($emailChanges) {
            $this->checkCurrentPassword($user, $data['current_password'] ?? null);
        }

        unset($data['current_password']);
        $user->fill($data);
        $changed = array_keys($user->getDirty());
        if ($changed) {
            try {
                DB::transaction(function () use ($user, $changed, $audit, $emailChanges, $oldEmail) {
                    $user->save();
                    $audit->log('account.profile_updated', $user, ['fields' => $changed]);
                    if ($emailChanges) {
                        // Tell the old address, which is the one that can spot a takeover.
                        $this->sendNotice($oldEmail, new SecurityNotice('email_changed', $user->name, $user->email));
                    }
                });
            } catch (UniqueConstraintViolationException) {
                // Another account took this email between validation and saving.
                throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
            }
        }

        return response()->json(['data' => $this->present($request)]);
    }

    public function password(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $rule = $user->role === Role::Admin
            ? Password::min(12)->letters()->numbers()->symbols()
            : Password::min(8)->letters()->numbers();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', $rule, 'max:200'],
        ]);
        $this->checkCurrentPassword($user, $data['current_password']);
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your current one.']);
        }

        $signedOut = DB::transaction(function () use ($user, $data, $request, $audit) {
            $user->password = $data['password'];
            $user->save();
            $signedOut = $this->endOtherSessions($request);
            $audit->log('account.password_changed', $user, ['other_sessions_ended' => $signedOut]);
            $this->sendNotice($user->email, new SecurityNotice('password_changed', $user->name));

            return $signedOut;
        });

        return response()->json(['data' => [...$this->present($request), 'signed_out' => $signedOut]]);
    }

    /** Sign out every other device, for example after using a shared computer. */
    public function signOutOthers(Request $request, AuditLogger $audit): JsonResponse
    {
        $signedOut = $this->endOtherSessions($request);
        if ($signedOut > 0) {
            $audit->log('account.signed_out_others', $request->user(), ['sessions' => $signedOut]);
        }

        return response()->json(['data' => [...$this->present($request), 'signed_out' => $signedOut]]);
    }

    /**
     * Wrong guesses are limited per account, so a token left open on a shared computer cannot be
     * used to work out the password. Typos in other fields are not counted.
     */
    private function checkCurrentPassword(User $user, ?string $password): void
    {
        if (! filled($password)) {
            throw ValidationException::withMessages(['current_password' => 'Enter your current password to make this change.']);
        }
        // Count the attempt before checking it, so a burst of parallel guesses cannot slip past the limit.
        $key = 'current-password|'.$user->id;
        if (RateLimiter::hit($key, 15 * 60) > self::PASSWORD_GUESSES) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['current_password' => "Too many wrong passwords. Try again in {$minutes} min."])->status(429);
        }
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not correct.']);
        }
        RateLimiter::clear($key);
    }

    /** Tokens of the user's other devices, keeping the one making this request. */
    private function otherTokens(Request $request): MorphMany
    {
        $current = $request->user()->currentAccessToken();

        return $request->user()->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()));
    }

    /** Delete the other devices' tokens and return how many of them were still signed in. */
    private function endOtherSessions(Request $request): int
    {
        $active = $this->activeOtherSessions($request);
        $this->otherTokens($request)->delete();

        return $active;
    }

    private function activeOtherSessions(Request $request): int
    {
        return $this->otherTokens($request)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
    }

    private function sendNotice(string $email, BaseNotification $notice): void
    {
        try {
            Notification::route('mail', $email)->notify($notice);
        } catch (\Throwable $e) {
            // The change is saved; a mail failure must not undo it or show the user an error.
            report($e);
        }
    }

    private function present(Request $request): array
    {
        $user = $request->user();
        $user->loadMissing('sellerProfile');

        return [
            ...(new UserResource($user))->resolve($request),
            'other_sessions' => $this->activeOtherSessions($request),
        ];
    }
}
