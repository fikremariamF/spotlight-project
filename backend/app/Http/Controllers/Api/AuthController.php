<?php

namespace App\Http\Controllers\Api;

use App\Enums\BusinessType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Area;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Alerts;
use App\Services\AuditLogger;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function accountRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * Emails are stored lowercase. Normalize before validation so the unique check
     * behaves the same on case-sensitive (SQLite) and case-insensitive (MySQL) collations.
     */
    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }

    public function register(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate($this->accountRules());
        $user = User::create($data);

        return $this->tokenResponse($user, 201);
    }

    /**
     * Seller application: creates a seller account with a pending storefront awaiting admin approval.
     */
    public function registerSeller(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            ...$this->accountRules(),
            'store_name' => ['required', 'string', 'min:2', 'max:120'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'store_phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'area_id' => ['required', 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            'address_line' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tin_number' => ['nullable', 'string', 'max:32'],
            'offers_custom_orders' => ['sometimes', 'boolean'],
            'offers_delivery' => ['sometimes', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($data, $audit) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $user->role = Role::Seller;
            $user->save();

            $area = Area::findOrFail($data['area_id']);

            $profile = new SellerProfile([
                'store_name' => $data['store_name'],
                'description' => $data['description'] ?? null,
                'business_type' => $data['business_type'],
                'phone' => $data['store_phone'],
                'tin_number' => $data['tin_number'] ?? null,
                'city_id' => $area->city_id,
                'area_id' => $area->id,
                'address_line' => $data['address_line'] ?? null,
                'offers_custom_orders' => $data['offers_custom_orders'] ?? false,
                'offers_delivery' => $data['offers_delivery'] ?? true,
            ]);
            $profile->slug = Slug::unique(SellerProfile::class, $data['store_name']);
            $user->sellerProfile()->save($profile);

            $audit->log('seller.applied', $profile, ['user_id' => $user->id]);
            app(Alerts::class)->sellerApplied($profile);

            return $user;
        });

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Hash even when the user does not exist so response time does not reveal registered emails.
        $passwordOk = Hash::check($data['password'], $user?->password ?? self::dummyHash());

        if (! $user || ! $passwordOk) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is suspended. Contact support.']);
        }

        return $this->tokenResponse($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('sellerProfile.city', 'sellerProfile.area'));
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equalizer');
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('web', expiresAt: now()->addDays(30))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('sellerProfile')),
        ], $status);
    }
}
