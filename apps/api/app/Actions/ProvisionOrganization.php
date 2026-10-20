<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Role;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an organization with its first administrator. Runs as system
 * work because no tenant exists yet.
 */
final class ProvisionOrganization
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    public function handle(User $owner, string $name): Organization
    {
        return $this->context->bypass(fn () => DB::transaction(function () use ($owner, $name) {
            $organization = Organization::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'industry' => 'healthcare',
            ]);

            Membership::create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => Role::OrgAdmin,
                'status' => Membership::ACTIVE,
            ]);

            Subscription::create([
                'organization_id' => $organization->id,
                'plan_id' => Plan::query()->where('code', 'starter')->value('id'),
                'status' => 'trialing',
                'current_period_end' => now()->addDays(30),
            ]);

            $this->audit->record('organization.created', $organization, null, [
                'name' => $organization->name,
                'owner_email' => $owner->email,
            ], $organization->id, $owner->id);

            return $organization;
        }));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'organization';
        $slug = $base;
        $i = 2;
        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
