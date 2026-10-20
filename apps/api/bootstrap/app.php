<?php

use App\Http\Middleware\RequireMfaEnrollment;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Every API request starts with no tenant and no bypass.
        $middleware->api(prepend: [ResetTenantContext::class]);
        $middleware->throttleApi();

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'mfa.enforced' => RequireMfaEnrollment::class,
        ]);

        // Pure JSON API: never redirect unauthenticated requests.
        $middleware->redirectGuestsTo(fn () => '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API routes always answer with JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
