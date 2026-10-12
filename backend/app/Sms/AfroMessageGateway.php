<?php

namespace App\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * AfroMessage (afromessage.com), a common SMS gateway in Ethiopia.
 * Needs AFROMESSAGE_TOKEN, plus AFROMESSAGE_IDENTIFIER_ID and AFROMESSAGE_SENDER once the
 * account has an approved sender name.
 */
class AfroMessageGateway implements SmsGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'afromessage';
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(string $to, string $body): ?string
    {
        if (empty($this->config['token'])) {
            throw new SmsFailed('AFROMESSAGE_TOKEN is not set.', retryable: false);
        }

        $payload = array_filter([
            'from' => $this->config['identifier_id'] ?? null,
            'sender' => $this->config['sender'] ?? null,
            'to' => $to,
            'message' => $body,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $response = Http::withToken($this->config['token'])
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim($this->config['base_url'], '/').'/send', $payload);
        } catch (ConnectionException $e) {
            throw new SmsFailed('Could not reach AfroMessage: '.$e->getMessage());
        }

        $json = $response->json();
        if ($response->successful() && is_array($json) && ($json['acknowledge'] ?? null) === 'success') {
            $id = $json['response']['message_id'] ?? null;

            return $id === null ? null : (string) $id;
        }

        $errors = $json['response']['errors'] ?? $json['errors'] ?? $json['message'] ?? $response->body();
        $detail = is_array($errors) ? implode('; ', array_map(fn ($e) => is_scalar($e) ? (string) $e : json_encode($e), $errors)) : (string) $errors;

        // 4xx answers (bad token, bad number, no balance) will not fix themselves on retry.
        throw new SmsFailed('AfroMessage refused the SMS (HTTP '.$response->status().'): '.mb_substr($detail, 0, 300), retryable: $response->serverError());
    }
}
