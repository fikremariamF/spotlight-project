<?php

namespace App\Enums;

/**
 * Where a customer's support inquiry stands. Customers see the same words as admins.
 */
enum SupportStatus: string
{
    // Sent by the customer; nobody at Betoch has picked it up yet.
    case New = 'new';
    case Open = 'open';
    // Betoch needs something from the customer (or is checking with the store).
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    // Final: the customer starts a new inquiry for anything else. Admins can reopen it.
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Open => 'Open',
            self::Waiting => 'Waiting for information',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * Still being worked on: shown in the admin's default list.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::New, self::Open, self::Waiting], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::New->value, self::Open->value, self::Waiting->value];
    }
}
