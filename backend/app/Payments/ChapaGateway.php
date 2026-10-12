<?php

namespace App\Payments;

use App\Models\Checkout;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Chapa (chapa.co): one hosted page for Telebirr, CBE Birr, M-Pesa, bank apps and cards.
 * Needs CHAPA_SECRET_KEY (a CHASECK_TEST key runs Chapa's test mode; no real money moves).
 */
class ChapaGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'chapa';
    }

    public function ready(): bool
    {
        return ! empty($this->config['secret_key']);
    }

    public function testMode(): bool
    {
        return str_starts_with((string) ($this->config['secret_key'] ?? ''), 'CHASECK_TEST');
    }

    public function initialize(Payment $payment, Checkout $checkout): string
    {
        $customer = $checkout->customer;
        [$first, $last] = self::splitName($checkout->recipient_name ?: ($customer?->name ?? 'Customer'));
        $app = rtrim((string) config('app.url'), '/');

        $payload = array_filter([
            'amount' => Money::toBirr($payment->amount_santim),
            'currency' => $payment->currency,
            'tx_ref' => $payment->tx_ref,
            'first_name' => $first,
            'last_name' => $last,
            'email' => self::usableEmail($customer?->email),
            'phone_number' => self::localPhone($checkout->phone),
            'callback_url' => $app.'/api/payments/chapa/callback',
            'return_url' => $app.'/payments/return?checkout='.$checkout->id,
            'customization' => [
                'title' => self::clean((string) config('marketplace.name'), 16),
                'description' => self::clean('Order payment '.$checkout->id, 50),
            ],
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url('/transaction/initialize'), $payload));
        $json = $response->json();
        $url = is_array($json) ? ($json['data']['checkout_url'] ?? null) : null;

        if ($response->successful() && ($json['status'] ?? null) === 'success' && is_string($url) && str_starts_with($url, 'https://')) {
            return $url;
        }

        throw new PaymentGatewayError('Chapa did not start the payment (HTTP '.$response->status().'): '.self::message($json, $response));
    }

    public function verify(Payment $payment): VerifyResult
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->url('/transaction/verify/'.rawurlencode($payment->tx_ref))));
        $json = $response->json();

        // Chapa answers "not found" until the customer has actually started paying.
        if ($response->status() === 404 || ($response->clientError() && stripos(self::message($json, $response), 'not found') !== false)) {
            return VerifyResult::pending();
        }
        if (! $response->successful() || ! is_array($json) || ! is_array($json['data'] ?? null)) {
            throw new PaymentGatewayError('Chapa could not check the payment (HTTP '.$response->status().'): '.self::message($json, $response));
        }

        $data = $json['data'];
        if (isset($data['tx_ref']) && (string) $data['tx_ref'] !== $payment->tx_ref) {
            throw new PaymentGatewayError('Chapa answered for a different payment.');
        }

        $status = match (strtolower((string) ($data['status'] ?? ''))) {
            'success' => 'paid',
            'failed', 'cancelled', 'canceled', 'reversed', 'refunded' => 'failed',
            default => 'pending',
        };

        return new VerifyResult(
            $status,
            self::santim($data['amount'] ?? null),
            isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 100) : null,
            isset($data['method']) || isset($data['payment_method']) ? mb_substr((string) ($data['method'] ?? $data['payment_method']), 0, 40) : null,
        );
    }

    /**
     * Webhooks are only a hint (we always verify), but unsigned noise is dropped. Chapa sends
     * x-chapa-signature (HMAC-SHA256 of the body) and chapa-signature (HMAC-SHA256 of the secret);
     * either matching is enough.
     */
    public function webhookSignatureValid(string $rawBody, ?string $xSignature, ?string $signature): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '') {
            return false;
        }
        $body = hash_hmac('sha256', $rawBody, $secret);
        $own = hash_hmac('sha256', $secret, $secret);

        return ($xSignature !== null && hash_equals($body, strtolower($xSignature)))
            || ($signature !== null && (hash_equals($own, strtolower($signature)) || hash_equals($body, strtolower($signature))));
    }

    public function webhookSecretSet(): bool
    {
        return ! empty($this->config['webhook_secret']);
    }

    private function send(\Closure $call): Response
    {
        if (! $this->ready()) {
            throw new PaymentGatewayError('CHAPA_SECRET_KEY is not set.');
        }
        try {
            return $call(Http::withToken((string) $this->config['secret_key'])->acceptJson()->asJson()->timeout($this->config['timeout'] ?? 15));
        } catch (ConnectionException $e) {
            throw new PaymentGatewayError('Could not reach Chapa: '.$e->getMessage());
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.chapa.co/v1'), '/').$path;
    }

    private static function message(mixed $json, Response $response): string
    {
        $message = is_array($json) ? ($json['message'] ?? null) : null;
        if (is_array($message)) {
            $message = implode('; ', array_map(fn ($m) => is_scalar($m) ? (string) $m : json_encode($m), array_merge(...array_map(fn ($m) => (array) $m, array_values($message)))));
        }

        return mb_substr((string) ($message ?? $response->body()), 0, 300);
    }

    private static function santim(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }
        // "20300.000" is still 20300 birr; digits past santim that are not zero make it unreadable.
        if (is_string($amount)) {
            $amount = preg_replace('/^(\d+\.\d{2})0+$/', '$1', trim($amount));
        }
        try {
            return Money::toSantim(is_string($amount) ? $amount : (float) $amount);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{0: string, 1: string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];
        $first = self::clean($parts[0] ?? '', 50) ?: 'Customer';

        return [$first, self::clean($parts[1] ?? '', 50) ?: $first];
    }

    /** Chapa takes 09xxxxxxxx or 07xxxxxxxx; anything else is left out (the field is optional). */
    public static function localPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '251') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 9 && in_array($digits[0], ['7', '9'], true)) {
            $digits = '0'.$digits;
        }

        return preg_match('/^0[79]\d{8}$/', $digits) ? $digits : null;
    }

    /** Test and placeholder addresses are rejected by providers; the field is optional. */
    public static function usableEmail(?string $email): ?string
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return preg_match('/\.(local|test|example|invalid|localhost)$/i', $email) ? null : $email;
    }

    /** Chapa's customization fields accept letters, numbers, spaces, dots, hyphens and underscores. */
    private static function clean(string $text, int $max): string
    {
        return trim(mb_substr(preg_replace('/[^A-Za-z0-9 ._-]/', '', $text) ?? '', 0, $max));
    }
}
