<?php

namespace App\Providers;

use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\NullGateway;
use App\Payments\PaymentGateway;
use App\Sms\AfroMessageGateway;
use App\Sms\LogSmsGateway;
use App\Sms\SmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, fn () => match (config('marketplace.alerts.sms_driver')) {
            'afromessage' => new AfroMessageGateway(config('services.afromessage')),
            default => new LogSmsGateway,
        });

        $this->app->singleton(PaymentGateway::class, fn () => match (config('marketplace.payments.gateway')) {
            'chapa' => new ChapaGateway(config('services.chapa')),
            // The test page can mark orders paid without money, so it is refused in production.
            'fake' => $this->app->isProduction() ? new NullGateway('fake (not allowed in production)') : new FakeGateway,
            default => new NullGateway,
        });
    }

    public function boot(): void
    {
        // Surface lazy loading and silently discarded attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // New inquiries are capped tighter than replies to slow down spam to sellers.
        RateLimiter::for('inquiries', fn (Request $request) => [
            Limit::perHour(10)->by('h|'.$request->user()?->id),
            Limit::perDay(30)->by('d|'.$request->user()?->id),
        ]);

        RateLimiter::for('messages', fn (Request $request) => [
            Limit::perMinute(20)->by('m|'.$request->user()?->id),
            Limit::perDay(500)->by('d|'.$request->user()?->id),
        ]);

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by('c|'.$request->user()?->id));

        // Provider callbacks only trigger a verify call, but an open endpoint still needs a ceiling.
        RateLimiter::for('payment-callbacks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(config('marketplace.catalog.requests_per_minute'))->by($request->user()?->id ?: $request->ip()));
    }
}
