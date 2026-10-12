<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\Alert;
use App\Notifications\Channels\SmsChannel;
use App\Services\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    /** @var list<array{user: int, kind: string, channel: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        Cache::flush();
        Event::listen(NotificationSent::class, function (NotificationSent $e) {
            $this->sent[] = [
                'user' => $e->notifiable->id,
                'kind' => $e->notification->kind,
                'channel' => $e->channel === SmsChannel::class ? 'sms' : $e->channel,
            ];
        });
    }

    /** Channels each user got for a kind, e.g. ['database', 'mail', 'sms']. */
    private function channels(User $user, string $kind): array
    {
        $channels = collect($this->sent)->where('user', $user->id)->where('kind', $kind)->pluck('channel')->sort()->values()->all();

        return $channels;
    }

    private function kinds(User $user): array
    {
        return collect($this->sent)->where('user', $user->id)->pluck('kind')->unique()->sort()->values()->all();
    }

    public function test_checkout_alerts_the_seller_on_every_channel_and_emails_the_customer_a_receipt(): void
    {
        $order = $this->placeOrder(2);
        $sellerUser = $this->seller->user;

        $this->assertSame(['database', 'mail', 'sms'], $this->channels($sellerUser, 'order.new'));
        $this->assertSame(['mail'], $this->channels($this->buyer, 'checkout.placed'));
        $this->assertSame(['checkout.placed'], $this->kinds($this->buyer));

        $sms = SmsMessage::sole();
        $this->assertSame('logged', $sms->status);
        $this->assertSame($this->seller->phone, $sms->to);
        $this->assertStringContainsString($order->number, $sms->body);
        $this->assertStringContainsString('/seller/orders/'.$order->id, $sms->body);

        $this->as($sellerUser)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.kind', 'order.new')
            ->assertJsonPath('data.0.title', "New order {$order->number}")
            ->assertJsonPath('data.0.url', "/seller/orders/{$order->id}");
    }

    public function test_a_refused_checkout_sends_nothing(): void
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => 1],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_alerts_inside_a_rolled_back_transaction_are_dropped(): void
    {
        try {
            DB::transaction(function () {
                app(Alerts::class)->sellerApplied($this->seller);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }
        $this->admin();
        $this->assertSame([], $this->sent);
    }

    public function test_customer_hears_about_each_step_with_sms_to_the_order_phone(): void
    {
        $order = $this->placeOrder(1);
        $this->sent = [];
        $seller = $this->seller->user;

        $this->as($seller)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->assertSame(['database', 'mail', 'sms'], $this->channels($this->buyer, 'order.confirmed'));
        // The account has no phone, so the SMS goes to the phone on the delivery address.
        $this->assertSame('+251911000111', SmsMessage::where('kind', 'order.confirmed')->sole()->to);

        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();
        $this->assertSame(['database'], $this->channels($this->buyer, 'order.processing'));
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'ready'])->assertOk();
        $this->assertSame(['database'], $this->channels($this->buyer, 'order.ready'), 'delivery orders only get an in-app "ready"');
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'dispatched'])->assertOk();
        $this->assertSame(['database', 'sms'], $this->channels($this->buyer, 'order.dispatched'));
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->assertSame(['database', 'mail'], $this->channels($this->buyer, 'order.delivered'));

        // The seller is never alerted about their own actions.
        $this->assertSame([], $this->kinds($seller));
    }

    public function test_account_mobile_number_wins_over_the_order_phone_and_landlines_are_skipped(): void
    {
        $this->buyer->forceFill(['phone' => '0922 33 44 55'])->save();
        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->assertSame('+251922334455', SmsMessage::where('kind', 'order.confirmed')->sole()->to);

        // A landline on the account falls back to the order's mobile number.
        $this->buyer->forceFill(['phone' => '011 551 2345'])->save();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'dispatched'])->assertOk();
        $this->assertSame('+251911000111', SmsMessage::where('kind', 'order.dispatched')->sole()->to);
    }

    public function test_pickup_orders_get_an_sms_when_ready(): void
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])->assertCreated();
        $cart = $this->getJson('/api/cart?address_id='.$this->address->id.'&fulfillment['.$this->seller->id.']=pickup')->json('data');
        $this->postJson('/api/checkout', [
            'address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $cart['grand_total_santim'],
            'fulfillment' => [$this->seller->id => 'pickup'],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertSame('pickup', $order->fulfillment);

        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'ready'])->assertOk();
        $this->assertSame(['database', 'sms'], $this->channels($this->buyer, 'order.ready'));
        $this->assertStringContainsString('ready for pickup', SmsMessage::where('kind', 'order.ready')->sole()->body);
    }

    public function test_cancellations_alert_the_other_side(): void
    {
        $seller = $this->seller->user;
        $admin = $this->admin();

        $byCustomer = $this->placeOrder(1);
        $this->sent = [];
        $this->as($this->buyer)->postJson("/api/orders/{$byCustomer->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertSame(['database', 'mail', 'sms'], $this->channels($seller, 'order.cancelled'));
        $this->assertSame([], $this->channels($this->buyer, 'order.cancelled'));

        $bySeller = $this->placeOrder(1);
        $this->sent = [];
        $this->as($seller)->postJson("/api/seller/orders/{$bySeller->id}/cancel", ['reason' => 'Out of fabric'])->assertOk();
        $this->assertSame(['database', 'mail', 'sms'], $this->channels($this->buyer, 'order.cancelled'));
        $this->assertSame([], $this->channels($seller, 'order.cancelled'));

        $byAdmin = $this->placeOrder(1);
        $this->sent = [];
        $this->as($admin)->postJson("/api/admin/orders/{$byAdmin->id}/cancel", ['reason' => 'Fraud check'])->assertOk();
        $this->assertSame(['database', 'mail', 'sms'], $this->channels($this->buyer, 'order.cancelled'));
        $this->assertSame(['database', 'mail', 'sms'], $this->channels($seller, 'order.cancelled'));

        $this->as($this->buyer)->getJson('/api/notifications')->assertJsonPath('data.0.body', 'The marketplace cancelled it. Reason: Fraud check');
    }

    public function test_preferences_and_the_admin_switch_are_respected(): void
    {
        $seller = $this->seller->user;
        $seller->forceFill(['alert_sms' => false])->save();
        $this->placeOrder(1);
        $this->assertSame(['database', 'mail'], $this->channels($seller, 'order.new'));

        $seller->forceFill(['alert_sms' => true, 'alert_email' => false])->save();
        $this->sent = [];
        $this->placeOrder(1);
        $this->assertSame(['database', 'sms'], $this->channels($seller, 'order.new'));

        Setting::updateOrCreate(['key' => 'sms_enabled'], ['value' => '0']);
        $seller->forceFill(['alert_email' => true])->save();
        $this->sent = [];
        $this->placeOrder(1);
        $this->assertSame(['database', 'mail'], $this->channels($seller, 'order.new'));
        $this->assertSame(1, SmsMessage::count(), 'only the SMS from the second order');
    }

    public function test_suspended_accounts_get_no_alerts(): void
    {
        $order = $this->placeOrder(1);
        $this->buyer->forceFill(['status' => 'suspended'])->save();
        $this->sent = [];
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->assertSame([], $this->kinds($this->buyer));
    }

    public function test_in_app_alerts_appear_even_when_no_queue_worker_runs(): void
    {
        config(['queue.default' => 'database']);
        $this->placeOrder(1);

        $this->assertSame(['database'], $this->channels($this->seller->user, 'order.new'));
        $this->assertSame(0, SmsMessage::count());
        // The email and SMS wait in the queue for the worker.
        $this->assertSame(3, DB::table('jobs')->count(), 'seller email, seller SMS, customer receipt');
    }

    public function test_messages_email_with_a_cooldown_and_new_inquiries_also_text_the_store_once_a_day(): void
    {
        $seller = $this->seller->user;
        $start = fn (Product $p) => $this->as($this->buyer)->postJson('/api/conversations', ['product_slug' => $p->slug, 'type' => 'question', 'body' => 'Is this available in grey?'])->json('data.id');

        $id = $start($this->sofa);
        $this->assertSame(['mail', 'sms'], $this->channels($seller, 'message.inquiry'));
        $this->assertSame([], $this->channels($seller, 'message.new'));

        // A follow-up from the customer inside the cooldown sends nothing more.
        $this->postJson("/api/conversations/{$id}/messages", ['body' => 'Also, what about delivery?'])->assertCreated();
        $this->assertSame([], $this->channels($seller, 'message.new'));

        // The store's reply emails the customer once.
        $this->as($seller)->postJson("/api/conversations/{$id}/messages", ['body' => 'Yes, in grey.'])->assertCreated();
        $this->postJson("/api/conversations/{$id}/messages", ['body' => 'Delivery is 300 birr.'])->assertCreated();
        $this->assertSame(['mail'], $this->channels($this->buyer, 'message.new'));

        // A new inquiry about another listing emails again but does not text the store again today.
        $chair = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $this->sent = [];
        $start($chair);
        $this->assertSame(['mail'], $this->channels($seller, 'message.inquiry'));

        $this->travel(25)->hours();
        $other = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $this->sent = [];
        $start($other);
        $this->assertSame(['mail', 'sms'], $this->channels($seller, 'message.inquiry'));

        // Replies never text anyone, even once the daily inquiry text is free again.
        $this->travel(25)->hours();
        $this->sent = [];
        $this->as($this->buyer)->postJson("/api/conversations/{$id}/messages", ['body' => 'Still there?'])->assertCreated();
        $this->as($seller)->postJson("/api/conversations/{$id}/messages", ['body' => 'Yes.'])->assertCreated();
        $this->assertSame(['mail'], $this->channels($seller, 'message.new'));
        $this->assertSame(['mail'], $this->channels($this->buyer, 'message.new'));
    }

    public function test_quotes_alert_through_the_quote_not_as_messages(): void
    {
        $seller = $this->seller->user;
        $this->seller->forceFill(['offers_custom_orders' => true])->save();
        $this->sofa->forceFill(['custom_order_available' => true])->save();
        $id = $this->as($this->buyer)->postJson('/api/conversations', ['product_slug' => $this->sofa->slug, 'type' => 'custom_request', 'body' => 'Can you make it 9,000?'])->assertCreated()->json('data.id');
        $this->sent = [];

        $quoteId = $this->as($seller)->postJson("/api/seller/conversations/{$id}/quotes", [
            'title' => 'Sofa at a better price', 'description' => 'Same sofa, delivered next week.', 'price' => '9000', 'valid_until' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->assertSame(['database', 'mail'], $this->channels($this->buyer, 'quote.new'));
        $this->assertSame([], $this->channels($this->buyer, 'message.new'));

        $this->as($this->buyer)->postJson("/api/quotes/{$quoteId}/accept")->assertOk();
        $this->assertSame(['database', 'mail'], $this->channels($seller, 'quote.answered'));
        $this->assertSame([], $this->channels($seller, 'message.new'));
        $this->as($seller)->getJson('/api/notifications')->assertJsonPath('data.0.title', $this->buyer->name.' accepted your quote');
    }

    public function test_store_applications_and_reviews_alert_admins_and_sellers(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/auth/register-seller', [
            'name' => 'Sara Bekele', 'email' => 'sara@example.com', 'password' => 'secret-123', 'password_confirmation' => 'secret-123',
            'store_name' => 'Sara Woodworks', 'business_type' => 'workshop', 'store_phone' => '0933 111 222', 'area_id' => $this->area()->id,
        ])->assertCreated();
        $this->assertSame(['database', 'mail'], $this->channels($admin, 'admin.seller_applied'));

        $profile = User::where('email', 'sara@example.com')->first()->sellerProfile;
        $this->as($admin)->postJson("/api/admin/sellers/{$profile->id}/approve")->assertOk();
        $this->assertSame(['database', 'mail', 'sms'], $this->channels($profile->user, 'seller.approve'));
        $this->assertSame('+251933111222', SmsMessage::where('kind', 'seller.approve')->sole()->to);
    }

    public function test_listing_review_alerts(): void
    {
        $admin = $this->admin();
        $draft = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => 'draft']);
        $draft->images()->create(['path' => 'products/x/a.jpg', 'sort_order' => 0]);

        $this->as($this->seller->user)->postJson("/api/seller/products/{$draft->id}/submit")->assertOk();
        $this->assertSame(['database'], $this->channels($admin, 'admin.listing_submitted'));

        $this->as($admin)->postJson("/api/admin/products/{$draft->id}/reject", ['note' => 'Photo is blurry'])->assertOk();
        $this->assertSame(['database', 'mail'], $this->channels($this->seller->user, 'listing.rejected'));
        $this->as($this->seller->user)->getJson('/api/notifications')->assertJsonPath('data.0.body', 'Note from the marketplace: Photo is blurry');
    }

    public function test_reviews_replies_and_payments_alert_in_app(): void
    {
        $order = $this->deliver($this->placeOrder(2));
        $item = $order->items()->first();
        $seller = $this->seller->user;
        $this->sent = [];

        $reviewId = $this->as($this->buyer)->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 4, 'body' => 'Solid'])->assertCreated()->json('data.id');
        $this->assertSame(['database'], $this->channels($seller, 'review.new'));

        $this->as($seller)->postJson("/api/seller/reviews/{$reviewId}/reply", ['reply' => 'Thank you!'])->assertOk();
        $this->postJson("/api/seller/reviews/{$reviewId}/reply", ['reply' => 'Thank you again!'])->assertOk();
        $this->assertSame(['database'], $this->channels($this->buyer, 'review.reply'), 'only the first reply alerts');

        $this->as($this->admin())->postJson("/api/admin/settlements/{$this->seller->id}/payments", ['amount' => '500', 'method' => 'cash'])->assertCreated();
        $this->assertSame(['database', 'mail'], $this->channels($seller, 'balance.payment'));
        $this->as($seller)->getJson('/api/notifications')->assertJsonPath('data.0.body', 'You now owe ETB 1,500.');
    }

    public function test_notification_inbox_is_private_and_can_be_marked_read(): void
    {
        $this->placeOrder(1);
        $this->placeOrder(1);
        $seller = $this->seller->user;
        $ids = $this->as($seller)->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data')->json('data.*.id');
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread', 2);

        // Someone else's alert is not found.
        $this->as($this->buyer)->postJson("/api/notifications/{$ids[0]}/read")->assertNotFound();
        $this->getJson('/api/notifications')->assertJsonCount(0, 'data');

        $this->as($seller)->postJson("/api/notifications/{$ids[0]}/read")->assertOk()->assertJsonPath('data.id', $ids[0]);
        $this->postJson("/api/notifications/{$ids[0]}/read")->assertOk();
        $this->getJson('/api/notifications?filter=unread')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[1]);
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread', 0);
        $this->getJson('/api/notifications?filter=bogus')->assertUnprocessable();
        $this->postJson('/api/notifications/not-a-uuid/read')->assertNotFound();
    }

    public function test_alert_settings(): void
    {
        $seller = $this->seller->user;
        $this->as($seller)->getJson('/api/account/alerts')->assertOk()
            ->assertJsonPath('data.alert_sms', true)
            ->assertJsonPath('data.sms_to', $this->seller->phone)
            ->assertJsonPath('data.sms_to_source', 'store');

        $this->putJson('/api/account/alerts', ['alert_sms' => false, 'alert_email' => false, 'phone' => '0944 55 66 77'])->assertOk()
            ->assertJsonPath('data.alert_sms', false)
            ->assertJsonPath('data.alert_email', false)
            ->assertJsonPath('data.phone', '0944 55 66 77');
        $this->putJson('/api/account/alerts', ['phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->putJson('/api/account/alerts', ['alert_sms' => 'maybe'])->assertUnprocessable();

        // A customer with only a landline has nowhere to text.
        $this->as($this->buyer)->putJson('/api/account/alerts', ['phone' => '011 551 2345'])->assertOk()
            ->assertJsonPath('data.sms_to', null)
            ->assertJsonPath('data.sms_to_source', null);
        $this->putJson('/api/account/alerts', ['phone' => '+251 911 22 33 44'])->assertOk()
            ->assertJsonPath('data.sms_to', '+251911223344')
            ->assertJsonPath('data.sms_to_source', 'account');
        $this->putJson('/api/account/alerts', ['phone' => ''])->assertOk()->assertJsonPath('data.phone', null);

        // Role and status are not settable here.
        $this->putJson('/api/account/alerts', ['role' => 'admin', 'status' => 'active'])->assertOk();
        $this->assertSame('customer', $this->buyer->fresh()->role->value);
    }

    public function test_admin_sms_log_and_switch(): void
    {
        $this->placeOrder(1);
        $admin = $this->admin();

        $this->as($this->buyer)->getJson('/api/admin/sms')->assertForbidden();
        $local = '0'.substr($this->seller->phone, 4);
        $this->as($admin)->getJson('/api/admin/sms')->assertOk()
            ->assertJsonPath('meta.driver', 'log')
            ->assertJsonPath('meta.delivers', false)
            ->assertJsonPath('meta.last_30_days.logged', 1)
            ->assertJsonPath('data.0.kind', 'order.new');
        $this->getJson('/api/admin/sms?q='.urlencode($local))->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/sms?status=failed')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/sms?status=nope')->assertUnprocessable();

        $this->getJson('/api/admin/settings')->assertJsonPath('data.sms_enabled', true)->assertJsonPath('meta.sms_driver', 'log');
        $this->putJson('/api/admin/settings', ['sms_enabled' => false])->assertOk()->assertJsonPath('data.sms_enabled', false);
        $this->assertFalse(Setting::smsEnabled());
    }

    public function test_alert_text_formats_money(): void
    {
        $this->assertSame('ETB 12,500', Alerts::etb(1_250_000));
        $this->assertSame('ETB 0.05', Alerts::etb(5));
        $this->assertSame('ETB 1,000.50', Alerts::etb(100_050));
        $this->assertSame('https://shop.test/orders/1', (function () {
            config(['app.url' => 'https://shop.test/']);

            return Alert::link('/orders/1');
        })());
    }
}
