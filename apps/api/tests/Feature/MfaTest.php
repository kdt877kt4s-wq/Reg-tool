<?php

use App\Security\Totp;
use App\Support\Role;

it('enrolls in two-factor and returns one-time recovery codes', function () {
    $organization = createOrganization();
    $admin = addMember($organization, Role::OrgAdmin, mfa: false);

    $secret = actingIn($admin, $organization)->postJson('/api/v1/auth/mfa/setup')
        ->assertOk()
        ->assertJsonStructure(['secret', 'otpauth_uri'])
        ->json('secret');

    $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])
        ->assertUnprocessable();

    $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => Totp::code($secret, time())])
        ->assertOk()
        ->json('recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($admin->fresh()->hasMfa())->toBeTrue();

    $this->postJson('/api/v1/auth/mfa/setup')->assertStatus(409);
});

it('blocks organization features for an admin until two-factor is on', function () {
    $organization = createOrganization();
    $admin = addMember($organization, Role::OrgAdmin, mfa: false);

    actingIn($admin, $organization)->getJson('/api/v1/organization')
        ->assertForbidden()
        ->assertJsonPath('code', 'mfa_enrollment_required');

    enableMfa($admin);

    actingIn($admin->fresh(), $organization)->getJson('/api/v1/organization')->assertOk();
});

it('does not require two-factor for employees', function () {
    $organization = createOrganization();
    $employee = addMember($organization, Role::Employee, mfa: false);

    actingIn($employee, $organization)->getJson('/api/v1/organization')->assertOk();
});

it('does not let an admin turn two-factor off', function () {
    $organization = createOrganization();
    $admin = addMember($organization, Role::OrgAdmin);
    $secret = $admin->mfa_secret;

    actingIn($admin, $organization)->deleteJson('/api/v1/auth/mfa', [
        'password' => TEST_PASSWORD,
        'code' => Totp::code($secret, time()),
    ])->assertForbidden()->assertJsonPath('code', 'mfa_required_by_role');
});

it('lets an employee turn two-factor off with password and code', function () {
    $organization = createOrganization();
    $employee = addMember($organization, Role::Employee, mfa: true);

    actingIn($employee, $organization)->deleteJson('/api/v1/auth/mfa', [
        'password' => TEST_PASSWORD,
        'code' => Totp::code($employee->mfa_secret, time()),
    ])->assertOk();

    expect($employee->fresh()->hasMfa())->toBeFalse();
});
