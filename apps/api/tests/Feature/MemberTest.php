<?php

use App\Models\Department;
use App\Models\Membership;
use App\Support\Role;

beforeEach(function () {
    $this->org = createOrganization();
    $this->admin = addMember($this->org, Role::OrgAdmin);
    $this->employee = addMember($this->org, Role::Employee);
    $this->membershipId = fn ($user) => asSystem(fn () => Membership::withoutGlobalScopes()->where('user_id', $user->id)->value('id'));
});

it('changes a role and department', function () {
    $department = asSystem(fn () => Department::create(['organization_id' => $this->org->id, 'name' => 'Claims']));
    $id = ($this->membershipId)($this->employee);

    actingIn($this->admin, $this->org)
        ->patchJson("/api/v1/organization/members/{$id}", ['role' => 'department_manager', 'department_id' => $department->id])
        ->assertOk()
        ->assertJsonPath('data.role', 'department_manager')
        ->assertJsonPath('data.department.name', 'Claims');
});

it('rejects a department from another organization', function () {
    $other = createOrganization('Other Org');
    $foreignDepartment = asSystem(fn () => Department::create(['organization_id' => $other->id, 'name' => 'Elsewhere']));
    $id = ($this->membershipId)($this->employee);

    actingIn($this->admin, $this->org)
        ->patchJson("/api/v1/organization/members/{$id}", ['department_id' => $foreignDepartment->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['department_id']);
});

it('never leaves an organization without an active administrator', function () {
    $id = ($this->membershipId)($this->admin);

    actingIn($this->admin, $this->org)->patchJson("/api/v1/organization/members/{$id}", ['role' => 'employee'])
        ->assertUnprocessable()->assertJsonPath('code', 'last_admin');
    actingIn($this->admin, $this->org)->deleteJson("/api/v1/organization/members/{$id}")
        ->assertUnprocessable()->assertJsonPath('code', 'last_admin');
});

it('removes a member, who then loses access', function () {
    $id = ($this->membershipId)($this->employee);

    actingIn($this->admin, $this->org)->deleteJson("/api/v1/organization/members/{$id}")->assertOk();

    actingIn($this->employee, $this->org)->getJson('/api/v1/organization')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_a_member');
});
