<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading and missing attributes outside
        // production, so data-access bugs surface in tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Console commands (migrations, seeders, scheduled jobs) are trusted
        // system work and run outside tenant isolation; HTTP requests never do.
        // Commands that never touch the database (package:discover during a
        // build) must not fail because none is reachable; if the setting
        // cannot be applied, later queries simply fail closed.
        Event::listen(CommandStarting::class, function () {
            try {
                $this->app->make(TenantContext::class)->setBypass(true);
            } catch (Throwable) {
                // No database available for this command.
            }
        });
        Event::listen(CommandFinished::class, function () {
            try {
                $this->app->make(TenantContext::class)->setBypass(false);
            } catch (Throwable) {
                // No database available for this command.
            }
        });

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));

        // Idle timeout: a token unused for longer than the limit stops working.
        // Runs before Sanctum refreshes last_used_at.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            $idle = (int) config('ruleradar.session_idle_minutes');

            return $isValid && ($token->last_used_at === null || $token->last_used_at->gt(now()->subMinutes($idle)));
        });

        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('ruleradar.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)->by((string) ($request->input('challenge') ?: $request->user()?->id).'|'.$request->ip()));
        RateLimiter::for('public-forms', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
