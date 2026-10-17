<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSupportData;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Customers ask Betoch, never a store. Admins answer, take notes, assign and escalate.
 * Covers acceptance criteria 1, 2, 3, 5 and 6 through the API.
 */
class SupportTicketTest extends TestCase
{
    use MakesSupportData, PlacesOrders, RefreshDatabase {
        MakesSupportData::actAs insteadof PlacesOrders;
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->admin = $this->admin();
    }

    public function test_a_product_question_reaches_the_admin_inbox_not_the_store(): void
    {
        $id = $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug])->assertCreated()
            ->assertJsonPath('data.subject', $this->sofa->name)
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.status_label', 'New')
            ->assertJsonPath('data.topic_label', 'Product question')
            ->assertJsonPath('data.product.slug', $this->sofa->slug)
            ->assertJsonPath('data.store.store_name', $this->seller->store_name)
            ->assertJsonPath('data.messages.0.from', 'You')
            ->assertJsonMissingPath('data.customer')
            ->assertJsonMissingPath('data.assignee')
            ->assertJsonMissingPath('data.store.phone')
            ->json('data.id');

        $ticket = SupportTicket::findOrFail($id);
        $this->assertSame($this->seller->id, $ticket->seller_profile_id);

        // Admin inbox has it; the store has nothing.
        $this->actAs($this->admin)->getJson('/api/admin/support/tickets')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.needs_reply', true)
            ->assertJsonPath('data.0.customer.email', $this->buyer->email)
            ->assertJsonPath('meta.counts.new', 1);
        $this->actAs($this->seller->user)->getJson('/api/seller/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/support/tickets/$id")->assertForbidden();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->seller->user_id]);
    }

    public function test_order_store_and_general_questions(): void
    {
        $order = $this->placeOrder(1);

        $this->askSupport($this->buyer, ['topic' => 'refund', 'order_id' => $order->id, 'body' => 'The legs arrived broken.'])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Order '.$order->number)
            ->assertJsonPath('data.order.number', $order->number)
            ->assertJsonMissingPath('data.order.total_santim');
        $this->askSupport($this->buyer, ['topic' => 'custom', 'seller_slug' => $this->seller->slug, 'subject' => 'A bigger table', 'body' => 'Can a store make a 10-seat table?'])
            ->assertCreated()->assertJsonPath('data.subject', 'A bigger table')->assertJsonPath('data.store.slug', $this->seller->slug);
        $this->askSupport($this->buyer, ['topic' => 'delivery', 'body' => 'Do you deliver to Sululta?'])
            ->assertCreated()->assertJsonPath('data.subject', 'Delivery')->assertJsonPath('data.product', null);

        // Someone else's order cannot be attached, and gets the same answer as a missing one.
        $other = $this->placeOrder(1, $this->customer());
        $this->askSupport($this->buyer, ['topic' => 'order', 'order_id' => $other->id])->assertUnprocessable()
            ->assertJsonPath('errors.order_id.0', 'Choose one of your own orders.');
        $this->askSupport($this->buyer, ['topic' => 'order', 'order_id' => 999999])->assertUnprocessable()
            ->assertJsonPath('errors.order_id.0', 'Choose one of your own orders.');
    }

    public function test_validation(): void
    {
        $this->askSupport($this->buyer, ['topic' => 'gossip'])->assertUnprocessable()->assertJsonValidationErrors('topic');
        $this->askSupport($this->buyer, ['body' => ' '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->askSupport($this->buyer, ['body' => str_repeat('a', 3001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->askSupport($this->buyer, ['subject' => str_repeat('a', 161)])->assertUnprocessable()->assertJsonValidationErrors('subject');
        // One reference at most.
        $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug, 'seller_slug' => $this->seller->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('product_slug');

        // Hidden listings and stores cannot be referenced.
        $draft = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $this->askSupport($this->buyer, ['product_slug' => $draft->slug])->assertUnprocessable()->assertJsonValidationErrors('product_slug');
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->askSupport($this->buyer, ['seller_slug' => $this->seller->slug])->assertUnprocessable()->assertJsonValidationErrors('seller_slug');
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_second_question_on_the_same_listing_and_topic_joins_the_active_inquiry(): void
    {
        $first = $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug])->assertCreated()->json('data.id');
        $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug, 'body' => 'Also, what wood is it?'])
            ->assertOk()->assertJsonPath('data.id', $first)->assertJsonCount(2, 'data.messages');
        // A different topic is its own inquiry.
        $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug, 'topic' => 'price', 'body' => 'Any discount for two?'])
            ->assertCreated()->assertJsonPath('data.topic', 'price');

        // Once closed, the same question starts a new inquiry.
        $this->postJson("/api/support/tickets/$first/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug])->assertCreated();
        $this->assertSame(3, SupportTicket::count());
    }

    public function test_admin_replies_and_notes_stay_where_they_belong(): void
    {
        $id = $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug])->json('data.id');

        $this->actAs($this->admin);
        $this->postJson("/api/admin/support/tickets/$id/notes", ['body' => 'Customer called twice already. PRIVATE.'])->assertCreated()
            ->assertJsonPath('data.internal', true);
        $this->postJson("/api/admin/support/tickets/$id/messages", ['body' => 'Yes, 3 are in stock.', 'status' => 'waiting'])->assertCreated();

        $detail = $this->getJson("/api/admin/support/tickets/$id")->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.assignee.id', $this->admin->id)
            ->json('data');
        $this->assertNotNull($detail['first_response_at']);
        $kinds = collect($detail['messages'])->pluck('kind')->all();
        $this->assertContains('note', $kinds);
        $this->assertContains('event', $kinds);
        $this->assertContains($this->admin->name, collect($detail['messages'])->pluck('from')->all());

        // The customer sees their question and the answer, signed by Betoch Support, and nothing else.
        $customerView = $this->actAs($this->buyer)->getJson("/api/support/tickets/$id")->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.status_label', 'Waiting for information')
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.from', 'Betoch Support')
            ->assertJsonPath('data.messages.1.body', 'Yes, 3 are in stock.');
        $raw = $customerView->getContent();
        $this->assertStringNotContainsString('PRIVATE', $raw);
        $this->assertStringNotContainsString($this->admin->name, $raw);
        $this->assertStringNotContainsString('Assigned to', $raw);
        $this->assertStringNotContainsString('"internal"', $raw);
        $customerView->assertJsonMissingPath('data.messages.0.meta');

        // Even the admin's id is not in the customer's view.
        $this->assertSame(['customer', 'admin'], collect($customerView->json('data.messages'))->pluck('from_role')->all());
    }

    public function test_unread_badge_and_read_state(): void
    {
        $id = $this->askSupport($this->buyer)->json('data.id');
        $this->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 0);

        $this->actAs($this->admin)->postJson("/api/admin/support/tickets/$id/notes", ['body' => 'note'])->assertCreated();
        $this->actAs($this->buyer)->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 0);

        $this->actAs($this->admin)->postJson("/api/admin/support/tickets/$id/messages", ['body' => 'Hello'])->assertCreated();
        $this->actAs($this->buyer)->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 1);
        $this->getJson('/api/support/tickets')->assertJsonPath('data.0.unread', true)->assertJsonPath('data.0.last_message.from', 'admin');
        $this->getJson("/api/support/tickets/$id")->assertOk();
        $this->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_customer_reply_reopens_and_closed_inquiries_are_read_only(): void
    {
        $id = $this->askSupport($this->buyer)->json('data.id');
        $this->actAs($this->admin)->postJson("/api/admin/support/tickets/$id/messages", ['body' => 'Fixed.', 'status' => 'resolved'])->assertCreated();
        $this->assertSame('resolved', SupportTicket::find($id)->status->value);
        $this->assertNotNull(SupportTicket::find($id)->resolved_at);

        $this->actAs($this->buyer)->postJson("/api/support/tickets/$id/messages", ['body' => 'Not fixed, sorry.'])->assertCreated();
        $ticket = SupportTicket::find($id);
        $this->assertSame('open', $ticket->status->value);
        $this->assertNull($ticket->resolved_at);
        $this->assertSame('customer', $ticket->last_public_by);

        $this->postJson("/api/support/tickets/$id/close")->assertOk();
        $this->postJson("/api/support/tickets/$id/messages", ['body' => 'Hello?'])->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'This inquiry is closed. Start a new one if you still need help.');
        $this->postJson("/api/support/tickets/$id/messages", ['body' => '   '])->assertUnprocessable();

        // An admin can only write on a closed inquiry by reopening it with the reply.
        $this->actAs($this->admin)->postJson("/api/admin/support/tickets/$id/messages", ['body' => 'One more thing'])->assertUnprocessable();
        $this->postJson("/api/admin/support/tickets/$id/messages", ['body' => 'Reopening', 'status' => 'open'])->assertCreated();
        $this->assertSame('open', SupportTicket::find($id)->status->value);
    }

    public function test_status_assignment_and_escalation_are_tracked(): void
    {
        $id = $this->askSupport($this->buyer, ['topic' => 'refund', 'body' => 'I want my money back.'])->json('data.id');
        $colleague = $this->admin();

        $this->actAs($this->admin);
        $this->getJson('/api/admin/support/admins')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/admin/support/tickets/$id", ['assigned_to' => $colleague->id])->assertOk()->assertJsonPath('data.assignee.id', $colleague->id);
        $this->putJson("/api/admin/support/tickets/$id", ['escalated' => true])->assertUnprocessable()->assertJsonValidationErrors('escalation_reason');
        $this->putJson("/api/admin/support/tickets/$id", ['escalated' => true, 'escalation_reason' => 'Asks for a chargeback'])->assertOk()
            ->assertJsonPath('data.escalation_reason', 'Asks for a chargeback');
        $this->putJson("/api/admin/support/tickets/$id", ['status' => 'resolved', 'note' => 'Refund sent'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->putJson("/api/admin/support/tickets/$id", ['assigned_to' => null])->assertOk()->assertJsonPath('data.assignee', null);
        // Only active admins can be assigned.
        $this->putJson("/api/admin/support/tickets/$id", ['assigned_to' => $this->buyer->id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->putJson("/api/admin/support/tickets/$id", ['status' => 'pending'])->assertUnprocessable();

        $events = SupportMessage::where('support_ticket_id', $id)->where('kind', 'event')->orderBy('id')->pluck('body')->all();
        $this->assertSame([
            'Assigned to '.$colleague->name,
            'Escalated: Asks for a chargeback',
            'Status: New to Resolved. Refund sent',
            'Unassigned',
        ], $events);
        $this->assertTrue(SupportMessage::where('support_ticket_id', $id)->where('kind', 'event')->where('internal', false)->doesntExist());
        foreach (['support.created', 'support.assigned', 'support.escalated', 'support.status_changed'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('subject_id', $id)->exists(), $action);
        }
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $colleague->id, 'type' => 'support.assigned']);
    }

    public function test_admin_inbox_filters_and_search(): void
    {
        $new = $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug])->json('data.id');
        $mine = $this->askSupport($this->buyer, ['topic' => 'delivery', 'subject' => 'Sululta delivery', 'body' => 'Do you deliver there?'])->json('data.id');
        $done = $this->askSupport($this->customer(), ['topic' => 'other', 'body' => 'Just saying thanks'])->json('data.id');

        $this->actAs($this->admin);
        $this->postJson("/api/admin/support/tickets/$mine/messages", ['body' => 'Yes we do.'])->assertCreated();
        $this->putJson("/api/admin/support/tickets/$done", ['status' => 'closed'])->assertOk();

        $ids = fn (string $query) => collect($this->getJson('/api/admin/support/tickets'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$new, $mine], $ids(''));
        $this->assertSame([$new, $mine, $done], $ids('?status=all'));
        $this->assertSame([$done], $ids('?status=closed'));
        $this->assertSame([$mine], $ids('?assigned=me'));
        $this->assertSame([$new], $ids('?assigned=none'));
        $this->assertSame([$new], $ids('?needs_reply=1'));
        $this->assertSame([$mine], $ids('?topic=delivery'));
        $this->assertSame([$mine], $ids('?q=Sululta'));
        $this->assertSame([$new, $mine], $ids('?q='.urlencode($this->buyer->email)));
        $this->assertSame([$new], $ids('?q=%23'.$new));
        $this->assertSame([], $ids('?q=%25'));
        $this->getJson('/api/admin/support/tickets?status=lost')->assertUnprocessable();
    }

    public function test_customers_see_only_their_own_inquiries(): void
    {
        $id = $this->askSupport($this->buyer)->json('data.id');
        $stranger = $this->customer();

        $this->actAs($stranger);
        $this->getJson('/api/support/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/support/tickets/$id")->assertNotFound();
        $this->postJson("/api/support/tickets/$id/messages", ['body' => 'Mine now'])->assertNotFound();
        $this->postJson("/api/support/tickets/$id/close")->assertNotFound();
        $this->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 0);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $id)->count());
    }

    public function test_stores_and_customers_cannot_reach_the_admin_inbox(): void
    {
        $id = $this->askSupport($this->buyer)->json('data.id');

        foreach ([$this->buyer, $this->seller->user] as $user) {
            $this->actAs($user);
            $this->getJson('/api/admin/support/tickets')->assertForbidden();
            $this->getJson("/api/admin/support/tickets/$id")->assertForbidden();
            $this->postJson("/api/admin/support/tickets/$id/messages", ['body' => 'x'])->assertForbidden();
            $this->postJson("/api/admin/support/tickets/$id/notes", ['body' => 'x'])->assertForbidden();
            $this->putJson("/api/admin/support/tickets/$id", ['status' => 'closed'])->assertForbidden();
            $this->getJson('/api/admin/support/admins')->assertForbidden();
            $this->getJson('/api/admin/support/requests')->assertForbidden();
            $this->postJson('/api/admin/support/requests', [])->assertForbidden();
        }
        // Stores and admins cannot open customer inquiries either.
        foreach ([$this->seller->user, $this->admin] as $user) {
            $this->actAs($user)->postJson('/api/support/tickets', ['topic' => 'other', 'body' => 'Hello there'])->assertForbidden();
            $this->getJson('/api/support/tickets')->assertForbidden();
        }
        $this->assertSame('new', SupportTicket::find($id)->status->value);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $id)->count());
    }

    public function test_new_inquiries_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->askSupport($this->buyer, ['topic' => 'other', 'body' => "Question number $i"])->assertCreated();
        }
        $this->askSupport($this->buyer, ['topic' => 'other', 'body' => 'One too many'])->assertTooManyRequests();
    }

    public function test_suspended_customers_are_shut_out(): void
    {
        $id = $this->askSupport($this->buyer)->json('data.id');
        $this->buyer->forceFill(['status' => 'suspended'])->save();

        $this->actAs($this->buyer)->getJson("/api/support/tickets/$id")->assertForbidden();
        $this->postJson("/api/support/tickets/$id/messages", ['body' => 'hello'])->assertForbidden();
    }
}
