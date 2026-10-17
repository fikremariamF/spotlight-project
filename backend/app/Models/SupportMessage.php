<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * kind: message (to or from the customer), note (admins only), event (history, admins only),
 * offer (a priced offer shown to the customer). Written only by SupportService.
 */
class SupportMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
            'quote_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
