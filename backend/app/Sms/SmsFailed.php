<?php

namespace App\Sms;

use RuntimeException;

class SmsFailed extends RuntimeException
{
    /**
     * @param  bool  $retryable  false when trying again cannot help (bad number, missing settings)
     */
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
