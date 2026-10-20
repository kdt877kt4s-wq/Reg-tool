<?php

use App\Models\Department;
use App\Models\Membership;
use App\Models\OrganizationProfile;
use App\Support\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| These tests try to cross the tenant boundary, through the API and
| directly against the database, and expect to fail every time.
*/

beforeEach(function () {
    $this->orgA = createOrganization('Alpha Health Plan');
    $this->orgB = createOrganization('Bravo Health System');
    $this->adminA = addMember($this->orgA, Role::OrgAdmin);
    $this->adminB = addMember($this->orgB, Role::OrgAdmin);

    asSystem(function () {
        Department::create(['organization_id' => $this->orgA->id, 'name' => 'Claims']);
        Department::create(['organization_id' => $this->orgB->id, 'name' => 'Pharmacy']);
        foreach ([$this->orgA, $this->orgB] as $org) {
            OrganizationProfile::create([
                'organization_id' => $org->id,
                'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => [],
                'functions' => [], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
            ]);
        }
    });
});

it('connects as a role that row-level security applies to', function () {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

it('refuses another organization through the header', function () {
    actingIn($this->adminA, $this->orgB)->getJson('/api/v1/organization')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_a_member');
});

it('asks which organization when a user belongs to several and sends none', function () {
    addMember($this->orgB, Role::Auditor, $this->adminA);

    Sanctum::actingAs($this->adminA);
    $this->getJson('/api/v1/organization')->assertStatus(409)->assertJsonPath('code', 'organization_required');
});

it('only lists members and departments of the current organization', function () {
    $members = actingIn($this->adminA, $this->orgA)->getJson('/api/v1/organization/members')->assertOk()->json('data');
    expect(collect($members)->pluck('user.email'))->toContain($this->adminA->email)->not->toContain($this->adminB->email);

    $departments = actingIn($this->adminA, $this->orgA)->getJson('/api/v1/departments')->assertOk()->json('data');
    expect(collect($departments)->pluck('name')->all())->toBe(['Claims']);
});

it('cannot change a member of another organization', function () {
    $foreign = asSystem(fn () => Membership::withoutGlobalScopes()->where('user_id', $this->adminB->id)->value('id'));

    actingIn($this->adminA, $this->orgA)->patchJson("/api/v1/organization/members/{$foreign}", ['role' => 'employee'])
        ->assertNotFound();
});

it('hides other tenants rows at the database level, even without the app scope', function () {
    $context = app(TenantContext::class);
    $membership = asSystem(fn () => Membership::withoutGlobalScopes()->where('user_id', $this->adminA->id)->first());

    $context->setUser($this->adminA->id);
    $context->setOrganization($this->orgA, $membership);

    // Raw queries skip Eloquent scopes; only RLS stands in the way.
    expect(DB::table('organization_profiles')->pluck('organization_id')->all())->toBe([$this->orgA->id])
        ->and(DB::table('departments')->where('organization_id', $this->orgB->id)->count())->toBe(0)
        ->and(DB::table('organizations')->pluck('id')->all())->toBe([$this->orgA->id]);
});

it('fails closed with no tenant context', function () {
    app(TenantContext::class)->reset();

    expect(DB::table('organization_profiles')->count())->toBe(0)
        ->and(DB::table('memberships')->count())->toBe(0)
        ->and(Department::query()->count())->toBe(0);
});

it('rejects writing a row into another organization at the database level', function () {
    $context = app(TenantContext::class);
    $membership = asSystem(fn () => Membership::withoutGlobalScopes()->where('user_id', $this->adminA->id)->first());
    $context->setOrganization($this->orgA, $membership);

    expect(fn () => DB::transaction(fn () => DB::table('departments')->insert([
        'organization_id' => $this->orgB->id,
        'name' => 'Injected',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});
