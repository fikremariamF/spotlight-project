<?php

namespace Tests\Feature;

use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\Alert;
use App\Notifications\Channels\SmsChannel;
use App\Providers\AppServiceProvider;
use App\Sms\AfroMessageGateway;
use App\Sms\SmsFailed;
use App\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class SmsTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    private ScriptedGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->gateway = new ScriptedGateway;
        $this->app->instance(SmsGateway::class, $this->gateway);
    }

    private function alert(): Alert
    {
        $alert = new Alert(kind: 'test.kind', title: 'Title', body: 'Body', url: '/x', channels: ['sms'], sms: 'Hello');
        $alert->id = (string) Str::uuid7();

        return $alert;
    }

    private function channel(): SmsChannel
    {
        return app(SmsChannel::class);
    }

    public function test_a_retried_job_does_not_text_twice(): void
    {
        $user = $this->seller->user;
        $alert = $this->alert();
        $this->channel()->send($user, $alert);
        $this->channel()->send($user, $alert);

        $this->assertSame(1, $this->gateway->calls);
        $row = SmsMessage::sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame('scripted', $row->driver);
        $this->assertSame('msg-1', $row->provider_message_id);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('Hello', $row->body);
    }

    public function test_temporary_failures_are_retried_and_recorded(): void
    {
        $user = $this->seller->user;
        $alert = $this->alert();
        $this->gateway->failNext = new SmsFailed('Timeout', retryable: true);

        try {
            $this->channel()->send($user, $alert);
            $this->fail('A temporary failure must reach the queue so it retries.');
        } catch (SmsFailed) {
        }
        $this->assertSame('failed', SmsMessage::sole()->status);
        $this->assertSame('Timeout', SmsMessage::sole()->error);

        $this->channel()->send($user, $alert);
        $row = SmsMessage::sole();
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->error);
        $this->assertSame(2, $row->attempts);
    }

    public function test_permanent_failures_stop_without_retrying(): void
    {
        $this->gateway->failNext = new SmsFailed('Invalid number', retryable: false);
        $this->channel()->send($this->seller->user, $this->alert());

        $row = SmsMessage::sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Invalid number', $row->error);
    }

    public function test_a_failing_provider_never_breaks_the_order(): void
    {
        $this->gateway->failAlways = true;
        $order = $this->placeOrder(1);

        $this->assertNotNull($order);
        $this->assertSame('failed', SmsMessage::sole()->status);
        // The in-app alert and email still went out.
        $this->as($this->seller->user)->getJson('/api/notifications')->assertJsonPath('meta.unread', 1);
    }

    public function test_users_without_a_mobile_number_are_skipped(): void
    {
        $user = User::factory()->create(['phone' => '011 551 2345']);
        $this->channel()->send($user, $this->alert());
        $this->assertSame(0, SmsMessage::count());
        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_afromessage_sends_with_token_and_reads_the_message_id(): void
    {
        Http::fake(['api.afromessage.com/*' => Http::response(['acknowledge' => 'success', 'response' => ['status' => 'Send in progress', 'message_id' => 7781]])]);
        $gateway = new AfroMessageGateway(['base_url' => 'https://api.afromessage.com/api/', 'token' => 'secret-token', 'identifier_id' => 'ID123', 'sender' => 'BETOCH']);

        $this->assertSame('7781', $gateway->send('+251911234567', 'Hello'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.afromessage.com/api/send'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r['to'] === '+251911234567' && $r['message'] === 'Hello' && $r['from'] === 'ID123' && $r['sender'] === 'BETOCH');
    }

    public function test_afromessage_leaves_out_unset_sender_fields(): void
    {
        Http::fake(['*' => Http::response(['acknowledge' => 'success', 'response' => []])]);
        $gateway = new AfroMessageGateway(['base_url' => 'https://api.afromessage.com/api', 'token' => 't', 'identifier_id' => null, 'sender' => '']);

        $this->assertNull($gateway->send('+251911234567', 'Hi'));
        Http::assertSent(fn (Request $r) => ! isset($r['from']) && ! isset($r['sender']));
    }

    public function test_afromessage_errors(): void
    {
        $gateway = new AfroMessageGateway(['base_url' => 'https://api.afromessage.com/api', 'token' => 't']);

        $this->fakeHttp(Http::response(['acknowledge' => 'error', 'response' => ['errors' => ['Insufficient balance']]], 200));
        $e = $this->failure(fn () => $gateway->send('+251911234567', 'Hi'));
        $this->assertFalse($e->retryable);
        $this->assertStringContainsString('Insufficient balance', $e->getMessage());

        $this->fakeHttp(Http::response('Unauthorized', 401));
        $this->assertFalse($this->failure(fn () => $gateway->send('+251911234567', 'Hi'))->retryable);

        $this->fakeHttp(Http::response('Bad gateway', 502));
        $this->assertTrue($this->failure(fn () => $gateway->send('+251911234567', 'Hi'))->retryable);

        $this->fakeHttp(Http::failedConnection('Connection timed out'));
        $this->assertTrue($this->failure(fn () => $gateway->send('+251911234567', 'Hi'))->retryable);

        $this->fakeHttp(Http::response('<html>not json</html>', 200));
        $this->assertFalse($this->failure(fn () => $gateway->send('+251911234567', 'Hi'))->retryable);

        $noToken = new AfroMessageGateway(['base_url' => 'https://api.afromessage.com/api', 'token' => null]);
        $this->assertFalse($this->failure(fn () => $noToken->send('+251911234567', 'Hi'))->retryable);
    }

    public function test_driver_comes_from_config(): void
    {
        foreach (['afromessage' => true, 'log' => false, 'unknown' => false] as $driver => $delivers) {
            config(['marketplace.alerts.sms_driver' => $driver]);
            $this->app->forgetInstance(SmsGateway::class);
            (new AppServiceProvider($this->app))->register();
            $this->assertSame($delivers, app(SmsGateway::class)->delivers(), $driver);
        }
    }

    /** Each case needs a fresh fake: Http::fake() stubs stack and the first match wins. */
    private function fakeHttp(mixed $response): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $response]);
    }

    private function failure(\Closure $call): SmsFailed
    {
        try {
            $call();
        } catch (SmsFailed $e) {
            return $e;
        }
        $this->fail('Expected SmsFailed');
    }
}

class ScriptedGateway implements SmsGateway
{
    public int $calls = 0;

    public ?SmsFailed $failNext = null;

    public bool $failAlways = false;

    public function name(): string
    {
        return 'scripted';
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(string $to, string $body): ?string
    {
        $this->calls++;
        if ($this->failAlways) {
            throw new SmsFailed('Provider down');
        }
        if ($this->failNext) {
            $e = $this->failNext;
            $this->failNext = null;
            throw $e;
        }

        return 'msg-'.$this->calls;
    }
}
