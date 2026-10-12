<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->user = User::factory()->create(['name' => 'Abebe Kebede', 'email' => 'abebe@example.com', 'phone' => '0911223344']);
    }

    /** A request with a real Sanctum token, the way the app signs in. */
    private function send(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token())->json($method, $uri, $data);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->user)->createToken('web', expiresAt: now()->addDays(30))->plainTextToken;
    }

    public function test_guests_cannot_see_or_change_a_profile(): void
    {
        $this->getJson('/api/account/profile')->assertUnauthorized();
        $this->putJson('/api/account/profile', ['name' => 'X Y'])->assertUnauthorized();
        $this->putJson('/api/account/password', [])->assertUnauthorized();
        $this->postJson('/api/account/sign-out-others')->assertUnauthorized();
    }

    public function test_suspended_accounts_cannot_use_their_profile(): void
    {
        $token = $this->token();
        $this->user->forceFill(['status' => 'suspended'])->save();
        $this->send('GET', '/api/account/profile', token: $token)->assertForbidden();
        $this->send('PUT', '/api/account/profile', ['name' => 'New Name'], $token)->assertForbidden();
        $this->assertSame('Abebe Kebede', $this->user->fresh()->name);
    }

    public function test_shows_the_profile_without_secrets(): void
    {
        $res = $this->send('GET', '/api/account/profile')->assertOk();
        $res->assertJsonPath('data.name', 'Abebe Kebede')
            ->assertJsonPath('data.email', 'abebe@example.com')
            ->assertJsonPath('data.phone', '0911223344')
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.other_sessions', 0);
        $this->assertArrayNotHasKey('password', $res->json('data'));
        $this->assertArrayNotHasKey('remember_token', $res->json('data'));
    }

    public function test_name_and_phone_change_without_the_password(): void
    {
        $this->send('PUT', '/api/account/profile', ['name' => '  Abebe K.  ', 'phone' => ' 0922 33 44 55 '])
            ->assertOk()->assertJsonPath('data.name', 'Abebe K.')->assertJsonPath('data.phone', '0922 33 44 55');

        $this->send('PUT', '/api/account/profile', ['phone' => ''])->assertOk()->assertJsonPath('data.phone', null);
        $this->assertSame('account.profile_updated', AuditLog::latest('id')->first()->action);
        Notification::assertNothingSent();
    }

    public function test_invalid_details_are_refused(): void
    {
        foreach ([
            ['name' => 'A'],
            ['name' => ''],
            ['name' => str_repeat('a', 101)],
            ['name' => ['array']],
            ['email' => 'not-an-email'],
            ['email' => ''],
            ['phone' => 'call me'],
            ['phone' => '12'],
        ] as $bad) {
            $this->send('PUT', '/api/account/profile', [...$bad, 'current_password' => 'password'])->assertUnprocessable();
        }
        $fresh = $this->user->fresh();
        $this->assertSame(['Abebe Kebede', 'abebe@example.com', '0911223344'], [$fresh->name, $fresh->email, $fresh->phone]);
    }

    public function test_email_change_needs_the_current_password_and_tells_the_old_address(): void
    {
        $this->send('PUT', '/api/account/profile', ['email' => 'new@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->send('PUT', '/api/account/profile', ['email' => 'new@example.com', 'current_password' => 'wrong-pass1'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame('abebe@example.com', $this->user->fresh()->email);
        Notification::assertNothingSent();

        $this->send('PUT', '/api/account/profile', ['email' => ' New@Example.COM ', 'current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.com');

        Notification::assertSentOnDemand(SecurityNotice::class, function (SecurityNotice $n, array $channels, AnonymousNotifiable $to) {
            return $n->kind === 'email_changed' && $to->routes['mail'] === 'abebe@example.com' && $n->newEmail === 'new@example.com';
        });
        // The new email signs in; the old one no longer does.
        $this->postJson('/api/auth/login', ['email' => 'new@example.com', 'password' => 'password'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'abebe@example.com', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_same_email_in_another_case_is_not_a_change(): void
    {
        $this->send('PUT', '/api/account/profile', ['email' => 'ABEBE@example.com', 'name' => 'Abebe Kebede'])->assertOk();
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::where('action', 'account.profile_updated')->count(), 'nothing changed, nothing logged');
    }

    public function test_cannot_take_another_accounts_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->send('PUT', '/api/account/profile', ['email' => 'Taken@example.com', 'current_password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('abebe@example.com', $this->user->fresh()->email);
    }

    public function test_role_and_status_cannot_be_changed_here(): void
    {
        $this->send('PUT', '/api/account/profile', ['role' => 'admin', 'status' => 'active', 'alert_sms' => false, 'password' => 'Hacked-123'])->assertOk();
        $fresh = $this->user->fresh();
        $this->assertSame('customer', $fresh->role->value);
        $this->assertTrue($fresh->alert_sms);
        $this->assertTrue(Hash::check('password', $fresh->password));
    }

    public function test_password_change_checks_the_current_one_and_signs_out_other_devices(): void
    {
        $phone = $this->token();
        $laptop = $this->token();
        $expired = $this->user->createToken('web', expiresAt: now()->subDay());
        $this->send('GET', '/api/account/profile', token: $laptop)->assertJsonPath('data.other_sessions', 1);

        $this->send('PUT', '/api/account/password', ['current_password' => 'nope-123', 'password' => 'Better-pass-1', 'password_confirmation' => 'Better-pass-1'], $laptop)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->send('PUT', '/api/account/password', ['current_password' => 'password', 'password' => 'Better-pass-1', 'password_confirmation' => 'Other-pass-1'], $laptop)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->send('PUT', '/api/account/password', ['current_password' => 'password', 'password' => 'short1', 'password_confirmation' => 'short1'], $laptop)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->send('PUT', '/api/account/password', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], $laptop)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        $this->assertSame(3, PersonalAccessToken::count(), 'refused attempts sign nobody out');

        $this->send('PUT', '/api/account/password', ['current_password' => 'password', 'password' => 'Better-pass-1', 'password_confirmation' => 'Better-pass-1'], $laptop)
            ->assertOk()->assertJsonPath('data.signed_out', 1)->assertJsonPath('data.other_sessions', 0);

        $this->assertTrue(Hash::check('Better-pass-1', $this->user->fresh()->password));
        $this->send('GET', '/api/account/profile', token: $laptop)->assertOk();
        $this->send('GET', '/api/account/profile', token: $phone)->assertUnauthorized();
        $this->assertNull(PersonalAccessToken::find($expired->accessToken->id));
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n, $c, AnonymousNotifiable $to) => $n->kind === 'password_changed' && $to->routes['mail'] === 'abebe@example.com');
        $this->assertSame(1, AuditLog::where('action', 'account.password_changed')->count());

        $this->postJson('/api/auth/login', ['email' => 'abebe@example.com', 'password' => 'Better-pass-1'])->assertOk();
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $this->user->forceFill(['password' => 'Same-pass-1'])->save();
        $this->send('PUT', '/api/account/password', ['current_password' => 'Same-pass-1', 'password' => 'Same-pass-1', 'password_confirmation' => 'Same-pass-1'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Choose a password different from your current one.');
    }

    public function test_admins_need_a_stronger_password(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->token($admin);
        foreach (['Betterpass12', 'Short-pass1'] as $weak) {
            $this->send('PUT', '/api/account/password', ['current_password' => 'password', 'password' => $weak, 'password_confirmation' => $weak], $token)
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->send('PUT', '/api/account/password', ['current_password' => 'password', 'password' => 'Much-better-pass-1!', 'password_confirmation' => 'Much-better-pass-1!'], $token)
            ->assertOk();
    }

    public function test_sign_out_other_devices_keeps_this_one(): void
    {
        $here = $this->token();
        $there = $this->token();
        $otherUser = $this->token(User::factory()->create());

        $this->send('POST', '/api/account/sign-out-others', token: $here)->assertOk()->assertJsonPath('data.signed_out', 1);
        $this->send('GET', '/api/account/profile', token: $here)->assertOk();
        $this->send('GET', '/api/account/profile', token: $there)->assertUnauthorized();
        $this->send('GET', '/api/account/profile', token: $otherUser)->assertOk();

        $this->send('POST', '/api/account/sign-out-others', token: $here)->assertJsonPath('data.signed_out', 0);
        $this->assertSame(1, AuditLog::where('action', 'account.signed_out_others')->count(), 'only a real sign-out is logged');
    }

    public function test_wrong_current_passwords_are_limited_but_typos_are_not(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 10; $i++) {
            $this->send('PUT', '/api/account/profile', ['name' => 'X'], $token)->assertUnprocessable();
        }
        $this->send('PUT', '/api/account/profile', ['email' => 'next@example.com', 'current_password' => 'password'], $token)->assertOk();

        $change = fn (string $current) => $this->send('PUT', '/api/account/password', ['current_password' => $current, 'password' => 'Better-pass-1', 'password_confirmation' => 'Better-pass-1'], $token);
        for ($i = 0; $i < 5; $i++) {
            $change("guess-{$i}")->assertUnprocessable();
        }
        // Even the right password waits, and the email form shares the same limit.
        $change('password')->assertTooManyRequests()->assertJsonValidationErrors('current_password');
        $this->send('PUT', '/api/account/profile', ['email' => 'other@example.com', 'current_password' => 'password'], $token)->assertTooManyRequests();
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));

        $this->travel(16)->minutes();
        $change('password')->assertOk();
    }

    public function test_a_right_password_resets_the_wrong_guess_count(): void
    {
        $token = $this->token();
        $email = fn (string $to, string $current) => $this->send('PUT', '/api/account/profile', ['email' => $to, 'current_password' => $current], $token);
        for ($i = 0; $i < 4; $i++) {
            $email('a@example.com', 'wrong-1')->assertUnprocessable();
        }
        $email('a@example.com', 'password')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $email('b@example.com', 'wrong-1')->assertUnprocessable();
        }
        $email('b@example.com', 'password')->assertOk();
    }

    public function test_sellers_keep_their_store_details(): void
    {
        $seller = User::factory()->seller()->create(['name' => 'Owner']);
        $this->send('PUT', '/api/account/profile', ['name' => 'Owner Name'], $this->token($seller))
            ->assertOk()->assertJsonPath('data.role', 'seller')->assertJsonPath('data.name', 'Owner Name');
    }
}
