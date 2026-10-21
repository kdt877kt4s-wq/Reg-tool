<?php

use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Illuminate\Support\Facades\Notification;

/*
| The permission matrix, checked through the API for every role.
| Expected status for each [method, path, payload] per role.
*/

beforeEach(function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $this->org = createOrganization();
});

dataset('roles', [
    'org admin' => [Role::OrgAdmin],
    'compliance officer' => [Role::ComplianceOfficer],
    'department manager' => [Role::DepartmentManager],
    'employee' => [Role::Employee],
    'auditor' => [Role::Auditor],
]);

$profile = ['org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => [], 'functions' => [], 'products' => [], 'obligations' => []];

$matrix = [
    'view organization' => ['GET', '/api/v1/organization', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'rename organization' => ['PATCH', '/api/v1/organization', ['name' => 'Renamed Org'], ['org_admin' => 200, 'compliance_officer' => 403, 'department_manager' => 403, 'employee' => 403, 'auditor' => 403]],
    'view profile' => ['GET', '/api/v1/organization/profile', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'edit profile' => ['PUT', '/api/v1/organization/profile', $profile, ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 403, 'employee' => 403, 'auditor' => 403]],
    'list members' => ['GET', '/api/v1/organization/members', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 403, 'employee' => 403, 'auditor' => 403]],
    'invite member' => ['POST', '/api/v1/organization/invitations', ['email' => 'new.person@example.test', 'role' => 'employee'], ['org_admin' => 201, 'compliance_officer' => 403, 'department_manager' => 403, 'employee' => 403, 'auditor' => 403]],
    'list departments' => ['GET', '/api/v1/departments', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'create department' => ['POST', '/api/v1/departments', ['name' => 'Quality'], ['org_admin' => 201, 'compliance_officer' => 403, 'department_manager' => 403, 'employee' => 403, 'auditor' => 403]],
    'search regulations' => ['GET', '/api/v1/regulatory-documents', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'view source status' => ['GET', '/api/v1/sources', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'review queue' => ['GET', '/api/v1/assessments', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'list tasks' => ['GET', '/api/v1/tasks', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'create task' => ['POST', '/api/v1/tasks', ['title' => 'Review the new rule', 'priority' => 'medium'], ['org_admin' => 201, 'compliance_officer' => 201, 'department_manager' => 403, 'employee' => 403, 'auditor' => 403]],
    'view calendar' => ['GET', '/api/v1/calendar?from=2026-10-01&to=2026-10-31', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'view dashboard' => ['GET', '/api/v1/dashboard/summary', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'list notifications' => ['GET', '/api/v1/notifications', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 200, 'employee' => 200, 'auditor' => 200]],
    'view audit log' => ['GET', '/api/v1/audit-events', [], ['org_admin' => 200, 'compliance_officer' => 200, 'department_manager' => 403, 'employee' => 403, 'auditor' => 200]],
];

foreach ($matrix as $label => [$method, $path, $payload, $expected]) {
    it("enforces: {$label}", function (Role $role) use ($method, $path, $payload, $expected) {
        Notification::fake();
        $user = addMember($this->org, $role);

        actingIn($user, $this->org)
            ->json($method, $path, $payload)
            ->assertStatus($expected[$role->value]);
    })->with('roles');
}

it('returns the resolved permission list for the current role', function () {
    $auditor = addMember($this->org, Role::Auditor);

    actingIn($auditor, $this->org)->getJson('/api/v1/organization')
        ->assertOk()
        ->assertJsonPath('permissions', ['departments.view', 'profile.view', 'audit.view', 'regulations.view', 'assessments.view', 'reports.view']);
});
