<?php

namespace App\Sms;

interface SmsGateway
{
    /** Short name stored with each message, e.g. "log" or "afromessage". */
    public function name(): string;

    /** Whether messages really leave the platform. The log driver returns false. */
    public function delivers(): bool;

    /**
     * Send one SMS and return the provider's message id, if it gives one.
     *
     * @throws SmsFailed when the provider refuses the message or cannot be reached
     */
    public function send(string $to, string $body): ?string;
}
