<?php

namespace App\Models;

use App\Enums\SupportStatus;
use App\Enums\SupportTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer's inquiry to Betoch support. All attributes are set by SupportService; nothing is mass assigned.
 */
class SupportTicket extends Model
{
    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'topic' => SupportTopic::class,
            'status' => SupportStatus::class,
            'escalated_at' => 'datetime',
            'last_public_message_id' => 'integer',
            'last_public_message_at' => 'datetime',
            'customer_last_read_id' => 'integer',
            'last_activity_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /** Everything, notes and history included: admins only. */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /** What the customer may see. The one place that decides it. */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal', false);
    }

    public function latestPublicMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q->where('internal', false));
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->orderBy('id');
    }

    public function sellerRequests(): HasMany
    {
        return $this->hasMany(SellerRequest::class)->orderBy('id');
    }

    public function reference(): string
    {
        return '#'.$this->id;
    }

    public function isUnreadForCustomer(): bool
    {
        return $this->last_public_message_id !== null
            && ($this->customer_last_read_id === null || $this->last_public_message_id > $this->customer_last_read_id);
    }

    public function scopeUnreadForCustomer(Builder $query): void
    {
        $query->whereNotNull('last_public_message_id')
            ->where(fn (Builder $q) => $q->whereNull('customer_last_read_id')->orWhereColumn('last_public_message_id', '>', 'customer_last_read_id'));
    }

    /**
     * Inquiries an admin should look at: new ones, and active ones where the customer wrote last.
     */
    public function scopeNeedsReply(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('status', SupportStatus::New->value)
            ->orWhere(fn (Builder $q) => $q->whereIn('status', [SupportStatus::Open->value, SupportStatus::Waiting->value])->where('last_public_by', 'customer')));
    }

    /**
     * Never moves the pointer backwards.
     */
    public function markReadByCustomer(): void
    {
        if ($this->last_public_message_id !== null && $this->customer_last_read_id !== $this->last_public_message_id) {
            static::whereKey($this->id)
                ->where(fn (Builder $q) => $q->whereNull('customer_last_read_id')->orWhere('customer_last_read_id', '<', $this->last_public_message_id))
                ->update(['customer_last_read_id' => $this->last_public_message_id]);
            $this->customer_last_read_id = $this->last_public_message_id;
            $this->syncOriginalAttribute('customer_last_read_id');
        }
    }
}
