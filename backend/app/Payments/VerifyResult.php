<?php

namespace App\Payments;

/**
 * What a provider says about one payment. Amounts are in santim; null when the provider did not say.
 */
final class VerifyResult
{
    /**
     * @param  'paid'|'failed'|'pending'  $status  'pending' also covers "the customer never finished"
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $amountSantim = null,
        public readonly ?string $currency = null,
        public readonly ?string $reference = null,
        public readonly ?string $method = null,
    ) {}

    public static function pending(): self
    {
        return new self('pending');
    }
}
