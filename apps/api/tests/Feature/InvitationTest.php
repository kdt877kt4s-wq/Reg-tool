<?php

use App\Models\Invitation;
use App\Models\Membership;
use App\Notifications\OrganizationInvitation;
use App\Support\Role;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->org = createOrganization('Gulf Coast Health');
    $this->admin = addMember($this->org, Role::OrgAdmin);
});

/** Sends an invitation and returns the emailed token. */
function inviteAndCaptureToken(string $email, string $role = 'employee'): string
{
    actingIn(test()->admin, test()->org)
        ->postJson('/api/v1/organization/invitations', ['email' => $email, 'role' => $role])
        ->assertCreated()
        ->assertJsonMissingPath('data.token');

    $token = null;
    Notification::assertSentOnDemand(OrganizationInvitation::class, function ($notification, $channels, $notifiable) use ($email, &$token) {
        if ($notifiable->routes['mail'] !== $email) {
            return false;
        }
        $token = basename($notification->url);

        return true;
    });

    return $token;
}

it('emails an invitation link and shows it to the invitee', function () {
    $token = inviteAndCaptureToken('new.hire@example.test', 'department_manager');

    $this->getJson("/api/v1/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('organization_name', 'Gulf Coast Health')
        ->assertJsonPath('email', 'new.hire@example.test')
        ->assertJsonPath('role_label', 'Department manager')
        ->assertJsonPath('account_exists', false);
});

it('creates the account and membership on acceptance, then the person can sign in', function () {
    $token = inviteAndCaptureToken('new.hire@example.test');

    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Jordan Lee',
        'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD,
    ])->assertOk()->assertJsonMissingPath('token');

    $this->postJson('/api/v1/auth/login', ['email' => 'new.hire@example.test', 'password' => TEST_PASSWORD])->assertOk();

    asSystem(fn () => expect(Membership::withoutGlobalScopes()->where('organization_id', $this->org->id)->count())->toBe(2));

    // The link works once.
    $this->getJson("/api/v1/invitations/{$token}")->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
});

it('asks an existing account for its password instead of creating one', function () {
    $existing = createUser('consultant@example.test');
    $token = inviteAndCaptureToken('consultant@example.test', 'auditor');

    $this->getJson("/api/v1/invitations/{$token}")->assertJsonPath('account_exists', true);
    $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'not the password at all'])->assertUnprocessable();
    $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => TEST_PASSWORD])->assertOk();

    asSystem(fn () => expect(Membership::withoutGlobalScopes()->where('user_id', $existing->id)->value('role'))->toBe(Role::Auditor));
});

it('rejects expired and revoked invitations', function () {
    $token = inviteAndCaptureToken('late@example.test');
    asSystem(fn () => Invitation::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]));
    $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();

    $token = inviteAndCaptureToken('revoked@example.test');
    $id = asSystem(fn () => Invitation::withoutGlobalScopes()->where('email', 'revoked@example.test')->value('id'));
    actingIn($this->admin, $this->org)->deleteJson("/api/v1/organization/invitations/{$id}")->assertOk();
    $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'X Y', 'password' => TEST_PASSWORD, 'password_confirmation' => TEST_PASSWORD])
        ->assertNotFound();
});

it('does not invite someone who is already a member', function () {
    actingIn($this->admin, $this->org)
        ->postJson('/api/v1/organization/invitations', ['email' => $this->admin->email, 'role' => 'employee'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'already_member');
});
