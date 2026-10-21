<?php

use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(HealthcareTaxonomySeeder::class);
    $docs = frDocuments();
    fakeFederalRegister([$docs['2026-20134'], $docs['2026-20281']]);
    pollFederalRegister();

    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
    $this->employee = addMember($this->org, Role::Employee);
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $this->org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => ['medicare_advantage'],
        'functions' => ['claims'], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
    ]));
});

it('puts task due dates on the calendar, typed official or internal', function () {
    $due = today()->addDays(4)->toDateString();
    actingIn($this->officer, $this->org)->postJson('/api/v1/tasks', [
        'title' => 'Internal target', 'priority' => 'low', 'owner_id' => $this->employee->id,
        'due_date' => $due, 'due_date_type' => 'internal',
    ])->assertCreated();
    actingIn($this->officer, $this->org)->postJson('/api/v1/tasks', [
        'title' => 'Official one', 'priority' => 'high',
        'due_date' => $due, 'due_date_type' => 'official', 'due_date_basis' => 'Effective date of FR doc 2026-20134',
    ])->assertCreated();

    $entries = collect(actingIn($this->officer, $this->org)
        ->getJson('/api/v1/calendar?from='.today()->toDateString().'&to='.today()->addDays(30)->toDateString())
        ->assertOk()->json('data'))->where('kind', 'task_due');

    expect($entries->pluck('deadline_type')->sort()->values()->all())->toBe(['internal', 'official'])
        ->and($entries->firstWhere('deadline_type', 'official')['basis'])->toContain('2026-20134');

    // The employee sees only their own task.
    $mine = collect(actingIn($this->employee, $this->org)
        ->getJson('/api/v1/calendar?from='.today()->toDateString().'&to='.today()->addDays(30)->toDateString())
        ->json('data'))->where('kind', 'task_due');
    expect($mine->pluck('title')->all())->toBe(['Internal target']);
});

it('shows published effective and comment dates for profile matches, and nothing invented', function () {
    $dates = RegulatoryDocument::query()->get()
        ->flatMap(fn ($d) => array_filter([$d->effective_date, $d->comments_close_date]))
        ->map->toDateString();

    $from = $dates->min() ?? today()->toDateString();
    $to = min($dates->max() ?? $from, Carbon::parse($from)->addDays(399)->toDateString());
    $entries = collect(actingIn($this->officer, $this->org)->getJson("/api/v1/calendar?from={$from}&to={$to}")->assertOk()->json('data'))
        ->whereIn('kind', ['effective', 'comments_close']);

    // Every entry carries a date the Federal Register published.
    foreach ($entries as $e) {
        $doc = RegulatoryDocument::find($e['document']['id']);
        $published = $e['kind'] === 'effective' ? $doc->effective_date : $doc->comments_close_date;
        expect($published?->toDateString())->toBe($e['date'])
            ->and($e['deadline_type'])->toBe('official')
            ->and($e['profile_match'] || $e['assessed'])->toBeTrue();
    }
});

it('limits the calendar range', function () {
    actingIn($this->officer, $this->org)->getJson('/api/v1/calendar?from=2026-01-01&to=2027-12-31')->assertUnprocessable();
    actingIn($this->officer, $this->org)->getJson('/api/v1/calendar?from=2026-05-01&to=2026-04-01')->assertUnprocessable();
});

it('summarizes the overview from stored records', function () {
    actingIn($this->officer, $this->org)->postJson('/api/v1/tasks', [
        'title' => 'Mine', 'priority' => 'low', 'owner_id' => $this->employee->id,
        'due_date' => today()->addDays(2)->toDateString(), 'due_date_type' => 'internal',
    ])->assertCreated();

    $mine = actingIn($this->employee, $this->org)->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
    expect($mine['my_tasks'])->toMatchArray(['active' => 1, 'overdue' => 0, 'due_this_week' => 1])
        ->and($mine['tasks']['scope'])->toBe('visible')
        ->and($mine['weekly_publications'])->toHaveCount(12)
        ->and($mine['profile_configured'])->toBeTrue();

    $org = actingIn($this->officer, $this->org)->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
    expect($org['tasks'])->toMatchArray(['scope' => 'organization', 'active' => 1])
        ->and($org['my_tasks']['active'])->toBe(0);
});

it('does not count another organization\'s tasks', function () {
    actingIn($this->officer, $this->org)->postJson('/api/v1/tasks', ['title' => 'Ours', 'priority' => 'low'])->assertCreated();
    $other = createOrganization('Elsewhere');
    $outsider = addMember($other, Role::ComplianceOfficer);

    expect(actingIn($outsider, $other)->getJson('/api/v1/dashboard/summary')->json('data.tasks.active'))->toBe(0);
});
