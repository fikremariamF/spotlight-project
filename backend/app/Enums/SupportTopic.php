<?php

namespace App\Enums;

/**
 * What a customer's inquiry is about. Order topics can carry an order reference; the others a listing.
 */
enum SupportTopic: string
{
    case Product = 'product';
    case Availability = 'availability';
    case Price = 'price';
    case Custom = 'custom';
    case Delivery = 'delivery';
    case Order = 'order';
    case Return = 'return';
    case Refund = 'refund';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product question',
            self::Availability => 'Availability',
            self::Price => 'Price or offer',
            self::Custom => 'Custom order',
            self::Delivery => 'Delivery',
            self::Order => 'My order',
            self::Return => 'Return',
            self::Refund => 'Refund',
            self::Other => 'Something else',
        };
    }
}
