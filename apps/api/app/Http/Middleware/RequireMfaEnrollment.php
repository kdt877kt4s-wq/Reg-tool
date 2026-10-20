<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Org Admins and Compliance Officers must enroll in two-factor login
 * before they can use organization features.
 */
class RequireMfaEnrollment
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $role = $this->context->role();

        if ($role?->requiresMfa() && ! $request->user()->hasMfa()) {
            return response()->json([
                'message' => 'Set up two-factor login to continue. Your role requires it.',
                'code' => 'mfa_enrollment_required',
            ], 403);
        }

        return $next($request);
    }
}
