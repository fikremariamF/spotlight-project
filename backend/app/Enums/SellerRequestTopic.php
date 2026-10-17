<?php

namespace App\Enums;

/**
 * Why Betoch asks a store something. The store never sees the customer's question itself,
 * only what the admin writes.
 */
enum SellerRequestTopic: string
{
    case Stock = 'stock';
    case Specs = 'specs';
    case Price = 'price';
    case Delivery = 'delivery';
    case Fulfilment = 'fulfilment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stock check',
            self::Specs => 'Product details',
            self::Price => 'Price or custom offer',
            self::Delivery => 'Delivery arrangements',
            self::Fulfilment => 'Order fulfilment',
            self::Other => 'Other',
        };
    }
}
