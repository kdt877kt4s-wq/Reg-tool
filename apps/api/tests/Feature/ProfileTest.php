<?php

use App\Models\AuditEvent;
use App\Models\OrganizationProfileVersion;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;

beforeEach(function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $this->org = createOrganization();
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
});

function validProfile(array $overrides = []): array
{
    return array_merge([
        'org_types' => ['medicare_advantage_org', 'medicaid_mco'],
        'states' => ['TX', 'LA'],
        'programs' => ['medicare_advantage', 'medicaid_managed_care'],
        'functions' => ['claims', 'utilization_management'],
        'products' => ['HMO D-SNP'],
        'obligations' => ['Annual compliance program effectiveness review'],
    ], $overrides);
}

it('starts empty and incomplete', function () {
    actingIn($this->officer, $this->org)->getJson('/api/v1/organization/profile')
        ->assertOk()
        ->assertJsonPath('profile.completed', false)
        ->assertJsonPath('profile.version', 0)
        ->assertJsonPath('profile.org_types', []);
});

it('saves a new version each time and keeps the history', function () {
    actingIn($this->officer, $this->org)->putJson('/api/v1/organization/profile', validProfile())
        ->assertOk()
        ->assertJsonPath('profile.version', 1)
        ->assertJsonPath('profile.completed', true)
        ->assertJsonPath('profile.states', ['TX', 'LA']);

    actingIn($this->officer, $this->org)->putJson('/api/v1/organization/profile', validProfile(['states' => ['TX']]))
        ->assertOk()
        ->assertJsonPath('profile.version', 2);

    asSystem(function () {
        $versions = OrganizationProfileVersion::withoutGlobalScopes()->where('organization_id', $this->org->id)->orderBy('version')->get();
        expect($versions)->toHaveCount(2)
            ->and($versions[0]->snapshot['states'])->toBe(['TX', 'LA'])
            ->and($versions[1]->snapshot['states'])->toBe(['TX'])
            ->and(AuditEvent::withoutGlobalScopes()->where('action', 'profile.updated')->count())->toBe(2);
    });
});

it('rejects values that are not in the taxonomy', function () {
    actingIn($this->officer, $this->org)->putJson('/api/v1/organization/profile', validProfile(['programs' => ['made_up_program']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['programs.0']);
});

it('requires an organization type and at least one state', function () {
    actingIn($this->officer, $this->org)->putJson('/api/v1/organization/profile', validProfile(['org_types' => [], 'states' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['org_types', 'states']);
});
