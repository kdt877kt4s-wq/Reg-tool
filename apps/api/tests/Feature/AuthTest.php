<?php

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Security\RecoveryCodes;
use App\Security\Totp;
use App\Support\Role;
use Laravel\Sanctum\PersonalAccessToken;

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Pat Rivera',
        'email' => 'Pat@Example.test',
        'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD,
        'organization_name' => 'Lone Star Health Plan',
    ], $overrides);
}

it('registers a user with a new organization as its administrator', function () {
    $response = $this->postJson('/api/v1/auth/register', registerPayload())
        ->assertCreated()
        ->assertJsonStructure(['token', 'expires_at']);

    $user = User::where('email', 'pat@example.test')->firstOrFail();

    asSystem(function () use ($user) {
        $membership = Membership::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        expect($membership->role)->toBe(Role::OrgAdmin)
            ->and(Organization::find($membership->organization_id)->name)->toBe('Lone Star Health Plan')
            ->and(Subscription::withoutGlobalScopes()->where('organization_id', $membership->organization_id)->value('status'))->toBe('trialing')
            ->and(AuditEvent::withoutGlobalScopes()->where('action', 'organization.created')->count())->toBe(1);
    });

    // Sanctum tokens look like "<id>|rr_<random>".
    expect($response->json('token'))->toMatch('/^\d+\|rr_[A-Za-z0-9]{40,}/');
});

it('rejects a duplicate email and a short password', function () {
    createUser('pat@example.test');

    $this->postJson('/api/v1/auth/register', registerPayload(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('gives the same error for an unknown email and a wrong password', function () {
    createUser('pat@example.test');

    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => TEST_PASSWORD])->assertUnprocessable();
    $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => 'wrong password here'])->assertUnprocessable();

    expect($unknown->json('errors.email'))->toBe($wrong->json('errors.email'));
});

it('signs in directly when two-factor is off', function () {
    createUser('pat@example.test');

    $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => TEST_PASSWORD])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at']);
});

it('requires a TOTP code when two-factor is on, and refuses to reuse it', function () {
    $user = createUser('pat@example.test');
    $secret = enableMfa($user);

    $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => TEST_PASSWORD])
        ->assertOk()
        ->assertJsonPath('mfa_required', true)
        ->assertJsonMissing(['token'])
        ->json('challenge');

    $code = Totp::code($secret, time());
    $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $code])
        ->assertOk()
        ->assertJsonStructure(['token']);

    // The challenge is single-use, and so is the time step.
    $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $code])
        ->assertUnprocessable();

    $again = $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => TEST_PASSWORD])->json('challenge');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $again, 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('accepts a recovery code once', function () {
    $user = createUser('pat@example.test');
    enableMfa($user);
    $codes = RecoveryCodes::generate();
    $user->forceFill(['mfa_recovery_codes' => $codes['hashes']])->save();

    $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => TEST_PASSWORD])->json('challenge');

    $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $login(), 'recovery_code' => $codes['plain'][0]])->assertOk();
    $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $login(), 'recovery_code' => $codes['plain'][0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recovery_code']);
});

it('reports memberships and whether two-factor enrollment is required', function () {
    $organization = createOrganization('Bayou Medicaid Plan');
    $admin = addMember($organization, Role::OrgAdmin, mfa: false);

    actingIn($admin, $organization)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.mfa_enabled', false)
        ->assertJsonPath('mfa_enrollment_required', true)
        ->assertJsonPath('memberships.0.organization_name', 'Bayou Medicaid Plan')
        ->assertJsonPath('memberships.0.role', 'org_admin');
});

it('signs out by deleting the token', function () {
    $user = createUser('pat@example.test');
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => TEST_PASSWORD])->json('token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('expires a token after 30 idle minutes', function () {
    $user = createUser();
    $plain = $user->createToken('web')->plainTextToken;
    PersonalAccessToken::query()->update(['last_used_at' => now()->subMinutes(31)]);

    $this->withToken($plain)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('throttles repeated sign-in attempts', function () {
    createUser('pat@example.test');

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => 'wrong password here'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => 'wrong password here'])->assertTooManyRequests();
});

it('rejects unauthenticated requests with JSON 401', function () {
    $this->getJson('/api/v1/organization')->assertUnauthorized();
    $this->get('/api/v1/auth/me')->assertUnauthorized()->assertHeader('content-type', 'application/json');
});
