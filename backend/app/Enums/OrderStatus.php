<?php

namespace App\Enums;

enum OrderStatus: string
{
    // Placed with online payment and waiting for it; the store does not see it yet.
    case AwaitingPayment = 'awaiting_payment';
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    // Ready for dispatch, or ready for pickup when the customer collects.
    case Ready = 'ready';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    /**
     * Position in the forward flow; sellers may move forward any number of steps.
     */
    public function rank(): int
    {
        return match ($this) {
            self::AwaitingPayment => -1,
            self::PendingConfirmation => 0,
            self::Confirmed => 1,
            self::Processing => 2,
            self::Ready => 3,
            self::Dispatched => 4,
            self::Delivered => 5,
            self::Cancelled => 99,
        };
    }
}
