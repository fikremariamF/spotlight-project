<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::PartiallyReceived => 'Partly received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Sent to the supplier and still waiting for goods. */
    public function isOpen(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }
}
