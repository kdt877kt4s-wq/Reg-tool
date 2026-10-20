<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\OrganizationProfile;
use App\Models\Subscription;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        $tenant = $this->tenant();
        $organization = $tenant->organization();
        $membership = $tenant->membership();
        $profile = OrganizationProfile::query()->first();
        $subscription = Subscription::query()->with('plan:id,code,name')->first();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'industry' => $organization->industry,
            ],
            'membership' => [
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'department_id' => $membership->department_id,
            ],
            'permissions' => Permissions::for($membership->role),
            'profile' => [
                'completed' => $profile?->completed_at !== null,
                'version' => $profile?->version ?? 0,
            ],
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->code,
                'plan_name' => $subscription->plan->name,
                'status' => $subscription->status,
            ] : null,
        ]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('organization.update');

        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:160']]);
        $organization = $this->tenant()->organization();

        $before = ['name' => $organization->name];
        $organization->update(['name' => $data['name']]);
        $audit->record('organization.updated', $organization, $before, ['name' => $organization->name]);

        return response()->json(['organization' => ['id' => $organization->id, 'name' => $organization->name]]);
    }
}
