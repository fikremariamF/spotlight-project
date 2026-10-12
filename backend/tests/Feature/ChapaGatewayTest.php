<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\ChapaGateway;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * The Chapa connection against faked HTTP answers shaped like Chapa's documented responses.
 * Nothing here reaches Chapa itself.
 */
class ChapaGatewayTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    private const BASE = 'https://chapa.test/v1';

    private const PAGE = 'https://checkout.chapa.co/checkout/payment/abc123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://shop.example.et',
            'marketplace.payments.gateway' => 'chapa',
            'services.chapa' => ['secret_key' => 'CHASECK_TEST-abc', 'webhook_secret' => 'hook-secret', 'base_url' => self::BASE, 'timeout' => 5],
        ]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->setUpOrders();
        Http::preventStrayRequests();
    }

    /** What the faked verify endpoint answers next; Http::fake keeps the first matching stub, so this is swapped instead. */
    private array $verify = [['message' => 'Payment not found', 'status' => 'failed', 'data' => null], 404];

    private function initializeOk(): void
    {
        Http::fake([
            self::BASE.'/transaction/initialize' => Http::response(['message' => 'Hosted Link', 'status' => 'success', 'data' => ['checkout_url' => self::PAGE]]),
            self::BASE.'/transaction/verify/*' => fn () => Http::response(...$this->verify),
        ]);
    }

    private function verifyAnswer(array|string $body, int $status = 200): void
    {
        $this->verify = [$body, $status];
    }

    private function verified(string $txRef, string $status = 'success', string|int|float $amount = '20300.00', string $currency = 'ETB'): array
    {
        return ['message' => 'Payment details', 'status' => 'success', 'data' => [
            'first_name' => 'Abebe', 'currency' => $currency, 'amount' => $amount, 'charge' => '0.70', 'mode' => 'test',
            'method' => 'telebirr', 'type' => 'API', 'status' => $status, 'reference' => 'APfzWD3UqY1t', 'tx_ref' => $txRef,
        ]];
    }

    private function checkout(): TestResponse
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 2]);
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');

        return $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'online', 'expected_total_santim' => $expected], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_starting_a_payment_sends_chapa_the_order_and_returns_its_page(): void
    {
        $this->initializeOk();
        $this->buyer->forceFill(['name' => 'Abebe Kebede Tesfaye', 'email' => 'abebe@gmail.com'])->save();

        $res = $this->checkout()->assertCreated()->assertJsonPath('data.payment.payment.checkout_url', self::PAGE);
        $payment = Payment::sole();
        $this->assertSame('chapa', $payment->provider);
        $this->assertMatchesRegularExpression('/^BF-\d+-[A-Za-z0-9]{12}$/', $payment->tx_ref);

        Http::assertSent(function (HttpRequest $r) use ($payment, $res) {
            return $r->url() === self::BASE.'/transaction/initialize'
                && $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer CHASECK_TEST-abc')
                && $r['amount'] === '20300.00' && $r['currency'] === 'ETB' && $r['tx_ref'] === $payment->tx_ref
                && $r['first_name'] === 'Abebe' && $r['last_name'] === 'Kebede'
                && $r['email'] === 'abebe@gmail.com' && $r['phone_number'] === '0911000111'
                && $r['callback_url'] === 'https://shop.example.et/api/payments/chapa/callback'
                && $r['return_url'] === 'https://shop.example.et/payments/return?checkout='.$res->json('data.id')
                && mb_strlen($r['customization']['title']) <= 16
                && preg_match('/^[A-Za-z0-9 ._-]+$/', $r['customization']['description']);
        });
        $this->getJson('/api/config')->assertJsonPath('online_payments.test_mode', true);
    }

    public function test_chapa_refusing_to_start_keeps_the_order_and_allows_a_retry(): void
    {
        Http::fake([self::BASE.'/transaction/initialize' => Http::sequence()
            ->push(['message' => ['email' => ['The email must be a valid email address.']], 'status' => 'failed'], 400)
            ->push(['status' => 'success', 'data' => ['checkout_url' => 'http://not-https.example']])
            ->push(['status' => 'success', 'data' => ['checkout_url' => self::PAGE]]),
        ]);

        $res = $this->checkout()->assertCreated();
        $this->assertNull($res->json('data.payment.payment'));
        $this->assertStringContainsString('could not open the payment page', $res->json('data.payment_error'));
        $first = Payment::sole();
        $this->assertSame('failed', $first->status);
        $this->assertStringContainsString('valid email', $first->problem);
        $this->assertSame(OrderStatus::AwaitingPayment, Order::sole()->status, 'the order waits; the customer can retry or cancel');

        $id = $res->json('data.id');
        $this->postJson("/api/checkouts/{$id}/pay")->assertStatus(502);
        $this->postJson("/api/checkouts/{$id}/pay")->assertOk()->assertJsonPath('data.payment.checkout_url', self::PAGE);
        $this->assertSame(['failed', 'failed', 'pending'], Payment::orderBy('id')->pluck('status')->all());
    }

    public function test_unreachable_chapa_is_reported_not_hidden(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $res = $this->checkout()->assertCreated();
        $this->assertNotNull($res->json('data.payment_error'));
        $this->assertStringContainsString('Could not reach Chapa', Payment::sole()->problem);
    }

    public function test_the_callback_verifies_with_chapa_before_trusting_anything(): void
    {
        $this->initializeOk();
        $this->checkout();
        $payment = Payment::sole();

        // The callback's own "status=success" means nothing; only verify counts.
        $this->verifyAnswer(['message' => 'Payment not found', 'status' => 'failed', 'data' => null], 404);
        $this->getJson("/api/payments/chapa/callback?trx_ref={$payment->tx_ref}&ref_id=X&status=success")->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, Order::sole()->status);

        $this->verifyAnswer($this->verified($payment->tx_ref));
        $this->getJson("/api/payments/chapa/callback?trx_ref={$payment->tx_ref}&ref_id=X&status=success")->assertOk();
        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('APfzWD3UqY1t', $payment->provider_reference);
        $this->assertSame('telebirr', $payment->method);
        $this->assertSame(OrderStatus::PendingConfirmation, Order::sole()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/transaction/verify/'.$payment->tx_ref && $r->hasHeader('Authorization', 'Bearer CHASECK_TEST-abc'));
    }

    public function test_callback_handles_unknown_references_and_chapa_errors(): void
    {
        $this->initializeOk();
        $this->checkout();
        $payment = Payment::sole();

        $this->getJson('/api/payments/chapa/callback?trx_ref=BF-999-nope')->assertOk()->assertJsonPath('received', true);
        $this->getJson('/api/payments/chapa/callback')->assertOk();
        $this->getJson('/api/payments/chapa/callback?trx_ref='.urlencode("x' OR 1=1"))->assertOk();

        $this->verifyAnswer('<html>Bad gateway</html>', 502);
        $this->getJson("/api/payments/chapa/callback?trx_ref={$payment->tx_ref}")->assertStatus(503);
        $this->assertSame('pending', $payment->fresh()->status);

        // An answer about a different payment is not applied.
        $this->verifyAnswer($this->verified('BF-1-someoneelse'));
        $this->getJson("/api/payments/chapa/callback?trx_ref={$payment->tx_ref}")->assertStatus(503);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_chapa_statuses_and_amounts_map_correctly(): void
    {
        $this->initializeOk();
        $this->checkout();
        $payment = Payment::sole();
        $gateway = app(PaymentGateway::class);

        $cases = [
            ['success', '20300.00', 'paid', 2_030_000],
            ['success', 20300, 'paid', 2_030_000],
            ['success', 20300.5, 'paid', 2_030_050],
            ['success', '20300.000', 'paid', 2_030_000],
            ['pending', '20300.00', 'pending', 2_030_000],
            ['failed', '20300.00', 'failed', 2_030_000],
            ['cancelled', '20300.00', 'failed', 2_030_000],
            ['reversed', '20300.00', 'failed', 2_030_000],
            ['SUCCESS', '20300.00', 'paid', 2_030_000],
            ['weird', '20300.00', 'pending', 2_030_000],
            ['success', 'abc', 'paid', null],
            ['success', '20300.001', 'paid', null],
            ['success', '-20300', 'paid', null],
        ];
        foreach ($cases as [$chapa, $amount, $ours, $santim]) {
            $this->verifyAnswer($this->verified($payment->tx_ref, $chapa, $amount));
            $result = $gateway->verify($payment);
            $this->assertSame($ours, $result->status, "Chapa {$chapa}");
            $this->assertSame($santim, $result->amountSantim, "amount {$amount}");
        }

        $this->verifyAnswer(['status' => 'failed', 'message' => 'Invalid transaction or Transaction not found', 'data' => null], 400);
        $this->assertSame('pending', $gateway->verify($payment)->status);

        $this->verifyAnswer(['status' => 'failed', 'message' => 'Invalid API Key', 'data' => null], 401);
        $this->expectException(PaymentGatewayError::class);
        $gateway->verify($payment);
    }

    public function test_an_unreadable_amount_is_not_treated_as_paid(): void
    {
        $this->initializeOk();
        $this->checkout();
        $payment = Payment::sole();
        $this->verifyAnswer($this->verified($payment->tx_ref, 'success', 'abc'));
        $this->getJson("/api/payments/chapa/callback?trx_ref={$payment->tx_ref}")->assertOk();
        $this->assertSame(OrderStatus::AwaitingPayment, Order::sole()->status);
        $this->assertNotNull($payment->fresh()->problem);
    }

    public function test_webhook_signatures(): void
    {
        $this->initializeOk();
        $this->checkout();
        $payment = Payment::sole();
        $this->verifyAnswer($this->verified($payment->tx_ref));
        $body = json_encode(['event' => 'charge.success', 'tx_ref' => $payment->tx_ref, 'status' => 'success']);
        $call = fn (array $headers) => $this->call('POST', '/api/payments/chapa/webhook', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $headers), $body);

        $call([])->assertStatus(401);
        $call(['HTTP_X_CHAPA_SIGNATURE' => hash_hmac('sha256', $body, 'wrong')])->assertStatus(401);
        $call(['HTTP_X_CHAPA_SIGNATURE' => hash_hmac('sha256', $body.' ', 'hook-secret')])->assertStatus(401);
        $this->assertSame('pending', $payment->fresh()->status);

        $call(['HTTP_X_CHAPA_SIGNATURE' => strtoupper(hash_hmac('sha256', $body, 'hook-secret'))])->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);

        // The other header form (HMAC of the secret) is accepted too.
        $call(['HTTP_CHAPA_SIGNATURE' => hash_hmac('sha256', 'hook-secret', 'hook-secret')])->assertOk();

        // No webhook secret configured: every webhook is refused.
        config(['services.chapa.webhook_secret' => null]);
        $this->assertFalse((new ChapaGateway(config('services.chapa')))->webhookSignatureValid($body, hash_hmac('sha256', $body, ''), null));
    }

    public function test_webhooks_and_callbacks_are_refused_for_other_gateways(): void
    {
        config(['marketplace.payments.gateway' => 'fake']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->postJson('/api/payments/chapa/webhook', ['tx_ref' => 'x'])->assertNotFound();
    }

    public function test_customer_details_are_cleaned_for_chapa(): void
    {
        $this->assertSame('0911000111', ChapaGateway::localPhone('+251 911 000 111'));
        $this->assertSame('0711000111', ChapaGateway::localPhone('251711000111'));
        $this->assertSame('0911000111', ChapaGateway::localPhone('911000111'));
        $this->assertSame('0911000111', ChapaGateway::localPhone('0911-000-111'));
        $this->assertNull(ChapaGateway::localPhone('0811000111'));
        $this->assertNull(ChapaGateway::localPhone('12345'));
        $this->assertNull(ChapaGateway::localPhone(null));

        $this->assertNull(ChapaGateway::usableEmail('customer@demo.local'));
        $this->assertNull(ChapaGateway::usableEmail('a@b.test'));
        $this->assertNull(ChapaGateway::usableEmail('not-an-email'));
        $this->assertSame('x@gmail.com', ChapaGateway::usableEmail('x@gmail.com'));

        $this->assertSame(['Abebe', 'Abebe'], ChapaGateway::splitName('Abebe'));
        $this->assertSame(['Customer', 'Customer'], ChapaGateway::splitName('   '));
        $this->assertSame(['Selam', 'Tesfaye'], ChapaGateway::splitName('  Selam   Tesfaye '));
        // Amharic letters are dropped (Chapa takes plain text); a name made only of them falls back.
        $this->assertSame(['Customer', 'Customer'], ChapaGateway::splitName('ሰላም'));
    }

    public function test_without_a_secret_key_online_payment_is_off(): void
    {
        config(['services.chapa.secret_key' => null]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->getJson('/api/config')->assertJsonPath('online_payments.available', false);
        $this->checkout()->assertUnprocessable();
        Http::assertNothingSent();
    }
}
