<?php

use App\Models\OrganizationProfile;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\RuleRadarNotice;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
    $this->employee = addMember($this->org, Role::Employee);
});

function assign(array $overrides = []): int
{
    return actingIn(test()->officer, test()->org)->postJson('/api/v1/tasks', array_merge([
        'title' => 'Refresh the appeals letter template',
        'priority' => 'medium',
        'owner_id' => test()->employee->id,
    ], $overrides))->assertCreated()->json('data.id');
}

it('lists, counts and marks notifications as read', function () {
    assign();
    assign(['title' => 'Second task']);

    $list = actingIn($this->employee, $this->org)->getJson('/api/v1/notifications')->assertOk();
    expect($list->json('unread_count'))->toBe(2)
        ->and($list->json('data.0.path'))->toStartWith('/tasks/');

    $id = $list->json('data.0.id');
    actingIn($this->employee, $this->org)->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('unread_count', 1);
    actingIn($this->employee, $this->org)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
});

it('keeps notifications private to their recipient', function () {
    assign();
    $id = asSystem(fn () => UserNotification::where('user_id', $this->employee->id)->value('id'));

    expect(actingIn($this->officer, $this->org)->getJson('/api/v1/notifications')->json('data'))->toBe([]);
    actingIn($this->officer, $this->org)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
});

it('respects email preferences but always notifies in the app', function () {
    $prefs = actingIn($this->employee, $this->org)->getJson('/api/v1/notification-preferences')->assertOk()->json('data');
    expect(collect($prefs)->firstWhere('kind', 'task_assigned')['email'])->toBeTrue();

    actingIn($this->employee, $this->org)->putJson('/api/v1/notification-preferences', ['email' => ['task_assigned' => false]])
        ->assertOk();
    actingIn($this->employee, $this->org)->putJson('/api/v1/notification-preferences', ['email' => ['made_up' => false]])
        ->assertUnprocessable();

    assign();

    Notification::assertNothingSent();
    asSystem(fn () => expect(UserNotification::where('user_id', $this->employee->id)->whereNull('emailed_at')->count())->toBe(1));
});

it('sends due-soon and overdue reminders once each', function () {
    $soon = assign(['due_date' => today()->addDays(2)->toDateString(), 'due_date_type' => 'internal']);
    $late = assign(['title' => 'Late one', 'due_date' => today()->addDays(20)->toDateString(), 'due_date_type' => 'internal']);
    asSystem(fn () => Task::whereKey($late)->update(['due_date' => today()->subDay()]));

    $this->artisan('ruleradar:daily-notifications')->assertSuccessful();
    $this->artisan('ruleradar:daily-notifications')->assertSuccessful();

    asSystem(function () use ($soon, $late) {
        expect(UserNotification::where('kind', 'task_due_soon')->where('url', "/tasks/{$soon}")->count())->toBe(1)
            ->and(UserNotification::where('kind', 'task_overdue')->where('url', "/tasks/{$late}")->count())->toBe(1);
    });
});

it('sends a digest of new publications that match the profile', function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $docs = frDocuments();
    fakeFederalRegister([$docs['2026-20134'], $docs['2026-20281']]);
    pollFederalRegister();
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $this->org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => ['medicare_advantage'],
        'functions' => ['claims'], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
    ]));
    $matching = actingIn($this->officer, $this->org)->getJson('/api/v1/regulatory-documents?relevance=profile')->json('meta.total');

    $this->artisan('ruleradar:daily-notifications')->assertSuccessful();
    $this->artisan('ruleradar:daily-notifications')->assertSuccessful();

    asSystem(function () use ($matching) {
        $digests = UserNotification::where('kind', 'profile_matches')->get();
        if ($matching === 0) {
            expect($digests)->toHaveCount(0);

            return;
        }
        // One digest for the officer, not one per run, and never for an employee.
        expect($digests)->toHaveCount(1)
            ->and($digests[0]->user_id)->toBe($this->officer->id)
            ->and($digests[0]->body)->toContain('screening hint');
    });
    Notification::assertSentOnDemandTimes(RuleRadarNotice::class, $matching === 0 ? 0 : 1);
});
