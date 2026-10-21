<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The API serves JSON and file downloads only: nothing may frame, script or cache it. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! $headers->has('Cache-Control') || ! str_contains((string) $headers->get('Cache-Control'), 'no-store')) {
            $headers->set('Cache-Control', 'no-store, private');
        }
        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }
        $headers->remove('X-Powered-By');

        return $response;
    }
}
