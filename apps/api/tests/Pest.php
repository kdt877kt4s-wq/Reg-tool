<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Security\Totp;
use App\Support\Role;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Fixture helpers. Fixtures are system work, so they run with row-level
| security bypassed; the code under test never does.
*/

function asSystem(callable $callback): mixed
{
    return app(TenantContext::class)->bypass($callback);
}

const TEST_PASSWORD = 'correct horse battery staple';

function createUser(?string $email = null, bool $mfa = false): User
{
    $user = User::create([
        'name' => 'Test '.Str::random(4),
        'email' => $email ?? Str::lower(Str::random(8)).'@example.test',
        'password' => TEST_PASSWORD,
    ]);

    if ($mfa) {
        enableMfa($user);
    }

    // Reload so every column is present, as it is for a signed-in user.
    return $user->fresh();
}

/** Turns on two-factor for a user and returns the TOTP secret. */
function enableMfa(User $user): string
{
    $secret = Totp::generateSecret();
    $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();

    return $secret;
}

function createOrganization(string $name = 'Acme Health Plan'): Organization
{
    return asSystem(fn () => Organization::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
    ]));
}

/** Adds a user to an organization. Roles that require MFA get it unless told otherwise. */
function addMember(Organization $organization, Role $role, ?User $user = null, ?bool $mfa = null): User
{
    $user ??= createUser(mfa: $mfa ?? $role->requiresMfa());

    asSystem(fn () => Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => Membership::ACTIVE,
    ]));

    return $user;
}

/** Authenticate as a user and address requests to an organization. */
function actingIn(User $user, Organization $organization): TestCase
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Organization-Id', (string) $organization->id);
}
