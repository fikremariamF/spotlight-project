<?php

namespace App\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Local and test driver: records the message instead of sending it.
 */
class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function delivers(): bool
    {
        return false;
    }

    public function send(string $to, string $body): ?string
    {
        Log::info('SMS (not sent, log driver) to '.PhoneNumber::mask($to).': '.$body);

        return null;
    }
}
