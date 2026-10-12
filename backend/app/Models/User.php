<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Notifications\Alert;
use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// role and status are deliberately not fillable: they change only through explicit admin actions.
#[Fillable(['name', 'email', 'phone', 'password', 'alert_sms', 'alert_email'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'role' => 'customer',
        'status' => 'active',
        'alert_sms' => true,
        'alert_email' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
            'alert_sms' => 'boolean',
            'alert_email' => 'boolean',
        ];
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorites')->withTimestamps();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * In-app alerts, newest first. Alert ids are time-ordered, so they break ties within a second.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest()->orderByDesc('id');
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isApprovedSeller(): bool
    {
        return $this->role === Role::Seller
            && $this->sellerProfile?->isApproved() === true;
    }

    /**
     * Where SMS alerts go: the store phone for sellers, otherwise the account phone. Null when
     * neither is an Ethiopian mobile number. Alerts about an order fall back to the order's phone.
     */
    public function routeNotificationForSms(mixed $notification = null): ?string
    {
        $own = $this->role === Role::Seller
            ? ($this->loadMissing('sellerProfile')->sellerProfile?->phone ?? $this->phone)
            : $this->phone;

        return PhoneNumber::ethiopianMobile($own)
            ?? PhoneNumber::ethiopianMobile($notification instanceof Alert ? $notification->fallbackPhone : null);
    }
}
