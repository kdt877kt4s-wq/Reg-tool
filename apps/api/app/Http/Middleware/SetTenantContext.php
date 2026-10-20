<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after authentication.
 *   tenant:user          sets only the user (account-level routes)
 *   tenant:organization  also resolves the organization from the
 *                        X-Organization-Id header, or the user's single
 *                        active membership, and rejects non-members.
 */
class SetTenantContext
{
    public const HEADER = 'X-Organization-Id';

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $scope = 'organization'): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->context->setUser($user->id);

        if ($scope === 'user') {
            return $next($request);
        }

        $memberships = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $user->id)
            ->where('status', Membership::ACTIVE);

        $requested = $request->header(self::HEADER);

        if ($requested !== null) {
            if (! ctype_digit((string) $requested)) {
                return $this->error(400, 'invalid_organization', 'The organization header must be a numeric id.');
            }
            $membership = $memberships->where('organization_id', (int) $requested)->first();
        } else {
            $all = $memberships->limit(2)->get();
            if ($all->count() > 1) {
                return $this->error(409, 'organization_required', 'You belong to several organizations. Choose one.');
            }
            $membership = $all->first();
        }

        if ($membership === null) {
            return $this->error(403, 'not_a_member', 'You are not an active member of this organization.');
        }

        $organization = Organization::query()->findOrFail($membership->organization_id);
        $this->context->setOrganization($organization, $membership);

        return $next($request);
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
