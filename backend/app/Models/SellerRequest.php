<?php

namespace App\Models;

use App\Enums\SellerRequestTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Betoch asking a store something. The store sees this thread and nothing of the customer's inquiry.
 */
class SellerRequest extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'topic' => SellerRequestTopic::class,
            'last_message_id' => 'integer',
            'last_message_at' => 'datetime',
            'seller_last_read_id' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SellerRequestMessage::class)->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isUnreadForSeller(): bool
    {
        return $this->last_message_id !== null
            && ($this->seller_last_read_id === null || $this->last_message_id > $this->seller_last_read_id);
    }

    public function scopeUnreadForSeller(Builder $query): void
    {
        $query->whereNotNull('last_message_id')
            ->where(fn (Builder $q) => $q->whereNull('seller_last_read_id')->orWhereColumn('last_message_id', '>', 'seller_last_read_id'));
    }

    public function markReadBySeller(): void
    {
        if ($this->last_message_id !== null && $this->seller_last_read_id !== $this->last_message_id) {
            static::whereKey($this->id)
                ->where(fn (Builder $q) => $q->whereNull('seller_last_read_id')->orWhere('seller_last_read_id', '<', $this->last_message_id))
                ->update(['seller_last_read_id' => $this->last_message_id]);
            $this->seller_last_read_id = $this->last_message_id;
            $this->syncOriginalAttribute('seller_last_read_id');
        }
    }
}
