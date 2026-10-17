<?php

namespace App\Enums;

enum TransferStatus: string
{
    // The units left the first location and are not counted anywhere until they arrive.
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
