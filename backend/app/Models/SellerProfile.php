<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\SellerStatus;
use App\Services\InventoryService;
use App\Support\MapPin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// status, reviewed_at and reviewed_by are set only by admin review actions.
#[Fillable([
    'store_name', 'description', 'business_type', 'phone', 'tin_number',
    'city_id', 'area_id', 'address_line', 'offers_custom_orders', 'offers_delivery', 'latitude', 'longitude',
])]
class SellerProfile extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        // Every store has a default stock location from the start (see InventoryService).
        static::created(fn (SellerProfile $profile) => app(InventoryService::class)->defaultWarehouse($profile->id));
    }

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => SellerStatus::class,
            'offers_custom_orders' => 'boolean',
            'offers_delivery' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function isApproved(): bool
    {
        return $this->status === SellerStatus::Approved;
    }

    /**
     * Sellers whose storefront and listings may be shown publicly.
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', SellerStatus::Approved->value)
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'));
    }

    /** The map pin as [latitude, longitude], or null when none is set. */
    public function pin(): ?array
    {
        return MapPin::from($this->latitude, $this->longitude);
    }
}
